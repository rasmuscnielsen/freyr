<?php

namespace App\Finance\EnableBanking;

use App\Jobs\ConvertReportingAmountsJob;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\BankSyncRun;
use App\Models\Posting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EnableBankingConnectionManager
{
    public function __construct(
        private readonly EnableBankingClient $client,
        private readonly EnableBankingTransactionMapper $transactionMapper,
    ) {}

    public function startAuthorization(User $user, ?BankConnection $connection = null, ?string $aspspName = null, ?string $aspspCountry = null): string
    {
        $state = Str::uuid()->toString();
        $aspspName ??= $connection?->aspsp_name ?? (string) config('services.enable_banking.aspsp_name');
        $aspspCountry ??= $connection?->aspsp_country ?? (string) config('services.enable_banking.aspsp_country');

        return DB::transaction(function () use ($user, $connection, $state, $aspspName, $aspspCountry): string {
            $connection ??= $user->bankConnections()->create([
                'provider' => BankConnection::PROVIDER_ENABLE_BANKING,
                'status' => BankConnection::STATUS_PENDING,
                'aspsp_name' => $aspspName,
                'aspsp_country' => $aspspCountry,
                'psu_type' => config('services.enable_banking.psu_type', 'personal'),
            ]);

            // Only freshly created rows start life as pending: an existing
            // connection keeps its status while consent is re-issued, so an
            // abandoned renewal can't strand it in a prunable state.
            $connection->update([
                'state' => $state,
                'aspsp_name' => $aspspName,
                'aspsp_country' => $aspspCountry,
                'failure_code' => null,
                'failure_message' => null,
            ]);

            $authorization = $this->client->startAuthorization(
                route('bank-connections.callback'),
                $state,
                $aspspName,
                $aspspCountry,
            );

            $connection->update([
                'authorization_id' => $authorization['authorization_id'] ?? null,
            ]);

            return (string) $authorization['url'];
        });
    }

    public function completeAuthorization(User $user, string $state, string $code): BankConnection
    {
        $connection = BankConnection::query()
            ->whereBelongsTo($user)
            ->where('state', $state)
            ->first();

        if (! $connection instanceof BankConnection) {
            return $this->connectionForConsumedState($user, $state);
        }

        $session = $this->client->createSession($code);

        $connection = DB::transaction(function () use ($connection, $session): BankConnection {
            $sessionId = (string) $session['session_id'];
            $accountPayloads = array_values(array_filter(
                Arr::wrap($session['accounts'] ?? []),
                'is_array',
            ));

            // The same bank login may be re-added through a brand-new
            // authorization: when the session's accounts already live on
            // another of the user's connections, the new consent folds into
            // that connection so linked accounts keep their links instead of
            // duplicating.
            $target = $this->connectionForAccounts($connection, $accountPayloads) ?? $connection;
            $authorizationId = $connection->authorization_id;

            if ($target->isNot($connection)) {
                if ($connection->status === BankConnection::STATUS_PENDING) {
                    // The fresh row only ferried the new consent over — drop
                    // it before the (unique) authorization id moves onto the
                    // target connection.
                    $connection->delete();
                } else {
                    // A real connection being renewed just happens to overlap
                    // the target's accounts — keep it, but release the
                    // consumed state (a replayed callback must not hit it
                    // again with a spent code) and the fresh authorization
                    // id, which is (unique) about to move onto the target.
                    $connection->update(['state' => null, 'authorization_id' => null]);
                }
            }

            $target->update([
                'status' => BankConnection::STATUS_ACTIVE,
                'state' => null,
                'authorization_id' => $authorizationId ?? $target->authorization_id,
                'session_id' => $sessionId,
                'session_id_hash' => hash('sha256', $sessionId),
                'aspsp_name' => (string) data_get($session, 'aspsp.name', $target->aspsp_name),
                'aspsp_country' => (string) data_get($session, 'aspsp.country', $target->aspsp_country),
                'psu_type' => (string) ($session['psu_type'] ?? $target->psu_type),
                'authorized_at' => now(),
                'access_valid_until' => CarbonImmutable::parse((string) data_get($session, 'access.valid_until')),
                'failure_code' => null,
                'failure_message' => null,
                'raw_session_payload' => $session,
            ]);

            foreach ($accountPayloads as $accountPayload) {
                $this->upsertAccount($target, $accountPayload);
            }

            return $target->refresh();
        });

        Cache::put(self::consumedStateKey($state), $connection->id, now()->addWeek());

        return $connection;
    }

    public function syncAccount(BankConnectionAccount $account): BankSyncRun
    {
        $account->loadMissing(['bankConnection', 'bankAccount']);
        $connection = $account->bankConnection;
        $dateFrom = $this->syncDateFrom($account);

        $run = BankSyncRun::query()->create([
            'user_id' => $connection->user_id,
            'bank_connection_id' => $connection->id,
            'bank_connection_account_id' => $account->id,
            'bank_account_id' => $account->bank_account_id,
            'status' => BankSyncRun::STATUS_RUNNING,
            'date_from' => $dateFrom,
            'started_at' => now(),
        ]);

        // Archived connections and closed accounts never sync. This surfaces
        // as a failed run rather than a connection failure — the dispatchers
        // filter both out anyway, so reaching this means a stale or manual
        // dispatch.
        $skippedReason = $connection->status === BankConnection::STATUS_ARCHIVED
            ? 'connection_archived'
            : ($account->bank_account_id === null
                ? 'account_unlinked'
                : ($account->bankAccount?->is_active === true ? null : 'account_archived'));

        if ($skippedReason !== null) {
            $run->update([
                'status' => $skippedReason === 'account_unlinked' ? BankSyncRun::STATUS_SKIPPED : BankSyncRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_code' => $skippedReason,
                'error_message' => match ($skippedReason) {
                    'connection_archived' => 'The bank connection is archived.',
                    'account_unlinked' => 'The bank account was unlinked before sync completed.',
                    default => 'The bank account is archived.',
                },
            ]);

            return $run;
        }

        try {
            if ($connection->needsRenewal()) {
                throw new RuntimeException('The bank connection needs renewal.', 401);
            }

            $balances = $this->client->balances((string) $account->external_account_uid);
            $transactions = $this->client->transactions(
                (string) $account->external_account_uid,
                $dateFrom,
                // The first sync is the only chance to fetch deep history, so
                // ask Enable Banking for the longest period the bank serves.
                $account->last_transaction_date === null ? 'longest' : null,
            );

            if ($account->last_transaction_date === null) {
                // The longest strategy can omit recent future-dated BOOK entries
                // already reflected in CLBD; the dated feed can include them.
                try {
                    $recentTransactions = $this->client->transactions(
                        (string) $account->external_account_uid,
                        CarbonImmutable::today()->subWeek(),
                    );
                } catch (RequestException $exception) {
                    if ($exception->response->json('error') !== 'WRONG_TRANSACTIONS_PERIOD') {
                        throw $exception;
                    }

                    $recentTransactions = [];
                }

                $transactions = [...$transactions, ...$recentTransactions];
            }

            $booked = $this->bookedBalance($balances);
            $postedTransactions = $this->postedTransactions($this->deduplicateTransactions($transactions), $balances);
            $lastTransactionDate = collect($postedTransactions)
                ->map(fn (array $transaction): CarbonImmutable => $this->transactionMapper->transactionDate($transaction))
                ->max();

            DB::transaction(function () use ($account, $connection, $run, $booked, $postedTransactions, $lastTransactionDate): void {
                $lockedAccount = BankConnectionAccount::query()
                    ->whereKey($account->id)
                    ->lockForUpdate()
                    ->first();

                if ($lockedAccount?->bank_account_id !== $account->bank_account_id
                    || $lockedAccount->status !== BankConnectionAccount::STATUS_LINKED) {
                    $run->update([
                        'status' => BankSyncRun::STATUS_SKIPPED,
                        'finished_at' => now(),
                        'error_code' => 'account_unlinked',
                        'error_message' => 'The bank account was unlinked before sync completed.',
                    ]);

                    return;
                }

                BankAccount::query()->whereKey($account->bank_account_id)->lockForUpdate()->firstOrFail();
                $account->refresh();
                $counts = $this->importTransactions($account, $postedTransactions);
                $this->updateBankAccountBalances($account, $booked, $lastTransactionDate);

                $account->update([
                    'status' => BankConnectionAccount::STATUS_LINKED,
                    'last_synced_at' => now(),
                    'last_transaction_date' => $lastTransactionDate?->toDateString() ?? $account->last_transaction_date,
                ]);

                $connection->update([
                    'status' => BankConnection::STATUS_ACTIVE,
                    'last_successful_sync_at' => now(),
                    'failure_code' => null,
                    'failure_message' => null,
                ]);

                $run->update([
                    'status' => BankSyncRun::STATUS_SUCCEEDED,
                    'date_to' => $lastTransactionDate?->toDateString(),
                    'finished_at' => now(),
                    ...$counts,
                ]);
            });
        } catch (Throwable $exception) {
            $this->recordFailedSync($run, $account, $exception);
        }

        // Fill reporting amounts for any foreign-currency postings. This is
        // deliberately outside the sync transaction and failure-proof: a
        // conversion hiccup must never fail a committed bank sync, and a
        // later run retries whatever is still unresolved.
        if ($run->refresh()->status === BankSyncRun::STATUS_SUCCEEDED) {
            try {
                ConvertReportingAmountsJob::dispatch($connection->user_id)->afterCommit();
            } catch (Throwable) {
                // The next sync or a manual retry picks up the backlog.
            }
        }

        return $run;
    }

    /**
     * A completed authorization nulls `state`, so a replayed callback (browser
     * back/refresh, Enable Banking re-redirect) cannot find its connection by
     * state. Recently consumed states remember their connection for a week
     * and resolve to it instead of failing.
     */
    private function connectionForConsumedState(User $user, string $state): BankConnection
    {
        $connectionId = Cache::get(self::consumedStateKey($state));

        $connection = $connectionId === null
            ? null
            : $user->bankConnections()->find($connectionId);

        throw_unless($connection instanceof BankConnection, (new ModelNotFoundException)->setModel(BankConnection::class));

        return $connection;
    }

    private static function consumedStateKey(string $state): string
    {
        return "enable-banking.consumed-state.{$state}";
    }

    public function syncDateFrom(BankConnectionAccount $account): ?CarbonImmutable
    {
        if ($account->last_transaction_date !== null) {
            $lastDate = CarbonImmutable::parse($account->last_transaction_date);

            return ($lastDate->isFuture() ? CarbonImmutable::today() : $lastDate)->subDay();
        }

        // A first sync fetches everything the bank offers unless the account
        // carries an import cutoff (a chosen history depth or a re-import
        // guard); the cutoff is then the suggested search start.
        $cutoff = $account->bankAccount?->ignore_postings_before;

        return $cutoff === null ? null : CarbonImmutable::parse($cutoff);
    }

    /**
     * @param  list<array<string, mixed>>  $transactions
     * @param  list<array<string, mixed>>  $balances
     * @return list<array<string, mixed>>
     */
    private function postedTransactions(array $transactions, array $balances): array
    {
        $bookedTransactions = collect($transactions)
            ->filter(fn (array $transaction): bool => ($transaction['status'] ?? null) === 'BOOK');
        $posted = $bookedTransactions
            ->reject(fn (array $transaction): bool => $this->transactionMapper->transactionDate($transaction)->isFuture());
        $clbd = collect($balances)->firstWhere('balance_type', 'CLBD');
        $currentBalance = data_get($clbd, 'balance_amount.amount');

        if ($currentBalance === null) {
            return $posted->values()->all();
        }

        $future = $bookedTransactions
            ->filter(fn (array $transaction): bool => $this->transactionMapper->transactionDate($transaction)->isFuture()
                && data_get($transaction, 'balance_after_transaction.amount') !== null)
            ->values();

        // Walk backward from the bank's current booked balance; only that
        // unique checkpoint chain can prove a future-dated BOOK is included.
        $includedFuture = [];

        while ($future->isNotEmpty()) {
            $matches = $future->filter(fn (array $transaction): bool => bccomp(
                (string) data_get($transaction, 'balance_after_transaction.amount'),
                (string) $currentBalance,
                2,
            ) === 0);

            if ($matches->count() !== 1) {
                break;
            }

            $index = $matches->keys()->first();
            $transaction = $future->pull($index);
            $includedFuture[] = $transaction;
            $currentBalance = bcsub((string) $currentBalance, $this->transactionMapper->signedAmount($transaction), 2);
        }

        return [...$posted->values()->all(), ...array_reverse($includedFuture)];
    }

    /**
     * Find another of the user's connections already holding one of the
     * session's accounts — i.e. the same bank login authorized again.
     *
     * @param  list<array<string, mixed>>  $accountPayloads
     */
    private function connectionForAccounts(BankConnection $connection, array $accountPayloads): ?BankConnection
    {
        $identificationHashes = array_filter(array_map(
            fn (array $payload): mixed => $payload['identification_hash'] ?? null,
            $accountPayloads,
        ));
        $ibans = array_filter(array_map(
            fn (array $payload): mixed => data_get($payload, 'account_id.iban'),
            $accountPayloads,
        ));

        if ($identificationHashes === [] && $ibans === []) {
            return null;
        }

        $matches = BankConnection::query()
            ->where('user_id', $connection->user_id)
            ->whereKeyNot($connection)
            ->with('accounts:id,bank_connection_id,identification_hash,iban')
            ->get()
            ->filter(
                fn (BankConnection $candidate): bool => $candidate->accounts->contains(
                    fn (BankConnectionAccount $remote): bool => ($remote->identification_hash !== null && in_array($remote->identification_hash, $identificationHashes, true))
                        || ($remote->iban !== null && in_array($remote->iban, $ibans, true))
                )
            );

        if ($matches->count() > 1) {
            throw new RuntimeException('Bank account identifiers match multiple connections.');
        }

        return $matches->first();
    }

    /**
     * @param  array<string, mixed>  $accountPayload
     */
    private function upsertAccount(BankConnection $connection, array $accountPayload): BankConnectionAccount
    {
        $uid = (string) $accountPayload['uid'];
        $uidHash = hash('sha256', $uid);
        $identificationHash = $accountPayload['identification_hash'] ?? null;

        $iban = data_get($accountPayload, 'account_id.iban');
        // IBAN is encrypted, so compare all identifiers on decrypted accounts.
        $matches = $connection->accounts()->get()->filter(
            fn (BankConnectionAccount $remote): bool => $remote->external_account_uid_hash === $uidHash
                || (filled($identificationHash) && $remote->identification_hash === $identificationHash)
                || (filled($iban) && $remote->iban === $iban),
        );

        if ($matches->count() > 1) {
            throw new RuntimeException('Bank account identifiers match multiple accounts.');
        }

        $account = $matches->first();

        if ($account !== null && $account->currency !== ($accountPayload['currency'] ?? null)) {
            throw new RuntimeException('A reauthorized bank account changed currency.');
        }

        $attributes = [
            'external_account_uid' => $uid,
            'external_account_uid_hash' => $uidHash,
            'identification_hash' => $identificationHash,
            'identification_hashes' => Arr::wrap($accountPayload['identification_hashes'] ?? []),
            'name' => (string) ($accountPayload['name'] ?? 'Bank account'),
            'details' => $accountPayload['details'] ?? null,
            'currency' => (string) ($accountPayload['currency'] ?? ''),
            'iban' => data_get($accountPayload, 'account_id.iban'),
            'account_number' => data_get($accountPayload, 'account_id.other.identification'),
            'account_number_last_four' => Str::of((string) data_get($accountPayload, 'account_id.other.identification'))->substr(-4)->toString(),
            'bic' => data_get($accountPayload, 'account_servicer.bic_fi'),
            'product' => $accountPayload['product'] ?? null,
            'cash_account_type' => $accountPayload['cash_account_type'] ?? null,
            'raw_account_payload' => $accountPayload,
        ];

        if ($account instanceof BankConnectionAccount) {
            $account->update([
                ...$attributes,
                'status' => $account->bank_account_id !== null
                    ? BankConnectionAccount::STATUS_LINKED
                    : ($account->status === BankConnectionAccount::STATUS_IGNORED
                        ? BankConnectionAccount::STATUS_IGNORED
                        : BankConnectionAccount::STATUS_UNLINKED),
            ]);

            return $account;
        }

        return $connection->accounts()->create([
            ...$attributes,
            'user_id' => $connection->user_id,
            'status' => BankConnectionAccount::STATUS_UNLINKED,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $transactions
     * @return list<array<string, mixed>>
     */
    private function deduplicateTransactions(array $transactions): array
    {
        $referenced = [];
        $unreferenced = [];

        foreach ($transactions as $transaction) {
            if (($transaction['status'] ?? null) !== 'BOOK') {
                continue;
            }

            $reference = $this->transactionMapper->entryReference($transaction);

            if ($reference === null) {
                $unreferenced[] = $transaction;

                continue;
            }

            if (isset($referenced[$reference])) {
                $previous = $referenced[$reference];

                if ($this->transactionMapper->signedAmount($previous) !== $this->transactionMapper->signedAmount($transaction)
                    || data_get($previous, 'transaction_amount.currency') !== data_get($transaction, 'transaction_amount.currency')
                    || ($previous['booking_date'] ?? null) !== ($transaction['booking_date'] ?? null)) {
                    throw new RuntimeException('Conflicting bank transactions share an entry reference.');
                }
            }

            $referenced[$reference] = $transaction;
        }

        return [...array_values($referenced), ...$unreferenced];
    }

    /**
     * @param  Builder<Posting>  $query
     */
    private function findPosting(Builder $query): ?Posting
    {
        try {
            return $query->sole();
        } catch (ModelNotFoundException) {
            return null;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $transactions
     * @return array{new_postings_count:int, updated_postings_count:int, duplicate_postings_count:int}
     */
    private function importTransactions(BankConnectionAccount $account, array $transactions): array
    {
        $counts = [
            'new_postings_count' => 0,
            'updated_postings_count' => 0,
            'duplicate_postings_count' => 0,
        ];

        $ignorePostingsBefore = $account->bankAccount?->ignore_postings_before;

        foreach ($transactions as $transaction) {
            $entryReference = $this->transactionMapper->entryReference($transaction);

            if ($account->bank_account_id === null) {
                continue;
            }

            if ($ignorePostingsBefore !== null
                && $this->transactionMapper->transactionDate($transaction)->lt($ignorePostingsBefore)) {
                continue;
            }

            $attributes = $this->transactionMapper->postingAttributes($account, $transaction);
            $query = Posting::query()
                ->where('user_id', $account->bankConnection->user_id)
                ->where('bank_account_id', $account->bank_account_id)
                ->where('source', BankConnectionAccount::SOURCE_ENABLE_BANKING);

            $posting = $entryReference === null ? null : $this->findPosting((clone $query)
                ->where(function ($query) use ($entryReference): void {
                    $query->where('external_posting_id', $entryReference)
                        ->orWhere('extra_data->entry_reference', $entryReference);
                }));

            if ($posting === null) {
                // note: identical reference-less payments collapse; occurrence matching if this becomes a real issue.
                $posting = $this->findPosting((clone $query)
                    ->when($entryReference !== null, fn (Builder $query): Builder => $query->whereNull('external_posting_id'))
                    ->where('original_description', $attributes['original_description'])
                    ->whereDate('date', $attributes['date'])
                    ->where('original_amount', $attributes['original_amount'])
                    ->where('original_currency', $attributes['original_currency'])
                );
            }

            if ($posting instanceof Posting) {
                $reportingAmount = $posting->amount;

                if ($entryReference === null && $posting->external_posting_id !== null) {
                    $attributes['external_posting_id'] = $posting->external_posting_id;
                }

                if ($posting->description !== $posting->original_description) {
                    unset($attributes['description']);
                }

                // PostgreSQL JSONB reorders object keys; that alone is not a provider update.
                if ($posting->extra_data == $attributes['extra_data']) {
                    unset($attributes['extra_data']);
                }

                $posting->fill($attributes);

                // A completed reporting conversion is stable: a re-sync must
                // not wipe it back to pending just because the bank sends the
                // account amount again.
                if ($attributes['amount'] === null
                    && $reportingAmount !== null
                    && ! $posting->isDirty(['original_amount', 'original_currency', 'date'])) {
                    $posting->amount = $reportingAmount;
                }

                if ($posting->isDirty()) {
                    $posting->save();
                    $counts['updated_postings_count']++;
                } else {
                    $counts['duplicate_postings_count']++;
                }

                continue;
            }

            Posting::query()->create($attributes);
            $counts['new_postings_count']++;
        }

        return $counts;
    }

    private function updateBankAccountBalances(BankConnectionAccount $account, ?array $booked, ?CarbonImmutable $lastTransactionDate): void
    {
        $bankAccount = $account->bankAccount;

        if (! $bankAccount instanceof BankAccount) {
            return;
        }

        $bankAccount->update([
            'current_balance' => data_get($this->accountCurrencyBalance($account, $booked), 'balance_amount.amount'),
            'last_updated_at' => now(),
            'last_known_posting_date' => $lastTransactionDate?->toDateString() ?? $account->last_transaction_date,
        ]);
    }

    /**
     * A provider balance only counts when it is denominated in the account currency.
     *
     * @param  array<string, mixed>|null  $booked
     * @return array<string, mixed>|null
     */
    private function accountCurrencyBalance(BankConnectionAccount $account, ?array $booked): ?array
    {
        if ($booked === null) {
            return null;
        }

        $currency = data_get($booked, 'balance_amount.currency');
        $accountCurrency = $account->bankAccount?->currency ?? $account->currency;

        return $currency === null || $currency === $accountCurrency ? $booked : null;
    }

    /**
     * Use the bank's booked snapshot when available, otherwise its first balance.
     *
     * @param  list<array<string, mixed>>  $balances
     * @return array<string, mixed>|null
     */
    private function bookedBalance(array $balances): ?array
    {
        return collect($balances)->firstWhere('balance_type', 'CLBD') ?? $balances[0] ?? null;
    }

    private function recordFailedSync(BankSyncRun $run, BankConnectionAccount $account, Throwable $exception): void
    {
        $connectionStatus = ($exception instanceof RequestException && in_array($exception->response->status(), [400, 401, 403, 404], true))
            || $exception->getCode() === 401
            ? BankConnection::STATUS_RENEWAL_REQUIRED
            : BankConnection::STATUS_FAILED;
        $errorCode = $exception instanceof RequestException
            ? (string) ($exception->response->json('error') ?? $exception->response->json('code') ?? $exception->response->status())
            : (string) $exception->getCode();
        $errorCode = preg_match('/\A[A-Z0-9_]{1,64}\z/', $errorCode) ? $errorCode : 'external_error';
        $message = $connectionStatus === BankConnection::STATUS_RENEWAL_REQUIRED
            ? 'The bank connection needs renewal.'
            : BankSyncRun::GENERIC_ERROR_MESSAGE;

        DB::transaction(function () use ($run, $account, $exception, $connectionStatus, $errorCode, $message): void {
            $lockedAccount = BankConnectionAccount::query()
                ->whereKey($account->id)
                ->lockForUpdate()
                ->first();

            if ($lockedAccount?->bank_account_id !== $account->bank_account_id
                || $lockedAccount->status !== BankConnectionAccount::STATUS_LINKED) {
                $run->update([
                    'status' => BankSyncRun::STATUS_SKIPPED,
                    'finished_at' => now(),
                    'error_code' => 'account_unlinked',
                    'error_message' => 'The bank account was unlinked before sync completed.',
                ]);

                return;
            }

            if ($connectionStatus === BankConnection::STATUS_RENEWAL_REQUIRED) {
                BankConnectionAccount::query()
                    ->whereKey($account->id)
                    ->whereNotNull('bank_account_id')
                    ->update(['status' => BankConnectionAccount::STATUS_RENEWAL_REQUIRED]);
            }

            $account->bankConnection->update([
                'status' => $connectionStatus,
                'last_failed_sync_at' => now(),
                'failure_code' => (string) $errorCode,
                'failure_message' => $message,
            ]);

            $run->update([
                'status' => BankSyncRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_code' => (string) $errorCode,
                'error_message' => $message,
                'raw_error' => [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            ]);
        });
    }
}
