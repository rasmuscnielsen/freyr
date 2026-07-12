<?php

namespace App\Finance\EnableBanking;

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\BankSyncRun;
use App\Models\Posting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
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

            $connection->update([
                'status' => BankConnection::STATUS_PENDING,
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
            ->firstOrFail();

        $session = $this->client->createSession($code);

        return DB::transaction(function () use ($connection, $session): BankConnection {
            $sessionId = (string) $session['session_id'];

            $connection->update([
                'status' => BankConnection::STATUS_ACTIVE,
                'state' => null,
                'session_id' => $sessionId,
                'session_id_hash' => hash('sha256', $sessionId),
                'aspsp_name' => (string) data_get($session, 'aspsp.name', $connection->aspsp_name),
                'aspsp_country' => (string) data_get($session, 'aspsp.country', $connection->aspsp_country),
                'psu_type' => (string) ($session['psu_type'] ?? $connection->psu_type),
                'authorized_at' => now(),
                'access_valid_until' => CarbonImmutable::parse((string) data_get($session, 'access.valid_until')),
                'failure_code' => null,
                'failure_message' => null,
                'raw_session_payload' => $session,
            ]);

            foreach (Arr::wrap($session['accounts'] ?? []) as $accountPayload) {
                if (! is_array($accountPayload)) {
                    continue;
                }

                $this->upsertAccount($connection, $accountPayload);
            }

            return $connection->refresh();
        });
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

        try {
            if ($connection->needsRenewal()) {
                throw new RuntimeException('The bank connection needs renewal.', 401);
            }

            $balances = $this->client->balances((string) $account->external_account_uid);
            $transactions = $this->client->transactions((string) $account->external_account_uid, $dateFrom);
            $postedTransactions = $this->postedTransactions($transactions);
            $counts = $this->importTransactions($account, $postedTransactions);
            $lastTransactionDate = collect($postedTransactions)
                ->map(fn (array $transaction): CarbonImmutable => $this->transactionMapper->transactionDate($transaction))
                ->max();

            $reconciliation = $this->reconcileBalance($account, $balances);

            DB::transaction(function () use ($account, $connection, $run, $balances, $counts, $lastTransactionDate, $reconciliation): void {
                $this->updateBankAccountBalances($account, $balances, $lastTransactionDate);

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
                    ...$reconciliation,
                ]);
            });
        } catch (Throwable $exception) {
            $this->recordFailedSync($run, $account, $exception);
        }

        return $run->refresh();
    }

    public function syncDateFrom(BankConnectionAccount $account): CarbonImmutable
    {
        if ($account->last_transaction_date !== null) {
            return CarbonImmutable::parse($account->last_transaction_date)->subDay();
        }

        return CarbonImmutable::now()->startOfYear();
    }

    /**
     * @param  list<array<string, mixed>>  $transactions
     * @return list<array<string, mixed>>
     */
    private function postedTransactions(array $transactions): array
    {
        return collect($transactions)
            ->filter(fn (array $transaction): bool => ! $this->transactionMapper->transactionDate($transaction)->isFuture())
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $accountPayload
     */
    private function upsertAccount(BankConnection $connection, array $accountPayload): BankConnectionAccount
    {
        $uid = (string) $accountPayload['uid'];
        $uidHash = hash('sha256', $uid);
        $identificationHash = $accountPayload['identification_hash'] ?? null;

        $account = BankConnectionAccount::query()
            ->whereBelongsTo($connection)
            ->where(function ($query) use ($uidHash, $identificationHash): void {
                $query->where('external_account_uid_hash', $uidHash);

                if (filled($identificationHash)) {
                    $query->orWhere('identification_hash', $identificationHash);
                }
            })
            ->first();

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
                'status' => $account->bank_account_id === null
                    ? BankConnectionAccount::STATUS_UNLINKED
                    : BankConnectionAccount::STATUS_LINKED,
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
            $transactionId = $this->transactionMapper->transactionId($transaction);

            if ($transactionId === '' || $account->bank_account_id === null) {
                continue;
            }

            if ($ignorePostingsBefore !== null
                && $this->transactionMapper->transactionDate($transaction)->lt($ignorePostingsBefore)) {
                continue;
            }

            $posting = Posting::query()
                ->where('user_id', $account->bankConnection->user_id)
                ->where('source', BankConnectionAccount::SOURCE_ENABLE_BANKING)
                ->where('external_account_uid_hash', $account->external_account_uid_hash)
                ->where('external_posting_id', $transactionId)
                ->first();

            $attributes = $this->transactionMapper->postingAttributes($account, $transaction);

            if ($posting instanceof Posting) {
                $posting->fill($attributes);

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

    /**
     * @param  list<array<string, mixed>>  $balances
     */
    private function updateBankAccountBalances(BankConnectionAccount $account, array $balances, ?CarbonImmutable $lastTransactionDate): void
    {
        $bankAccount = $account->bankAccount;

        if (! $bankAccount instanceof BankAccount) {
            return;
        }

        $booked = collect($balances)->firstWhere('balance_type', 'CLBD') ?? $balances[0] ?? null;

        $bankAccount->update([
            'current_balance' => data_get($booked, 'balance_amount.amount'),
            'last_updated_at' => now(),
            'last_known_posting_date' => $lastTransactionDate?->toDateString() ?? $account->last_transaction_date,
        ]);
    }

    /**
     * Reconcile our local running balance against the bank-reported booked balance.
     *
     * The expected figure is the bank's booked (CLBD) balance at sync time. The computed
     * figure is the running balance stored on the newest posting we hold for this account
     * (latest by date, then by id, among postings from this source with a non-null balance).
     *
     * The comparison is deliberately naive: it does not attempt to account for pending or
     * not-yet-booked transactions. If the newest local posting predates the balance snapshot
     * (e.g. the bank has booked movements we have not imported, or holds pending entries), the
     * two figures will simply disagree and `balance_matches` becomes false. That mismatch is the
     * intended warning signal, not an error — it never fails the run.
     *
     * When no posting carries a running balance, reconciliation cannot be performed and both the
     * computed balance and `balance_matches` are left null.
     *
     * @param  list<array<string, mixed>>  $balances
     * @return array{expected_balance: ?string, computed_balance: ?string, balance_matches: ?bool}
     */
    private function reconcileBalance(BankConnectionAccount $account, array $balances): array
    {
        $booked = collect($balances)->firstWhere('balance_type', 'CLBD') ?? $balances[0] ?? null;
        $expectedBalance = data_get($booked, 'balance_amount.amount');
        $expectedBalance = $expectedBalance === null ? null : (string) $expectedBalance;

        $computedBalance = null;

        if ($account->bank_account_id !== null) {
            $latestPosting = Posting::query()
                ->where('user_id', $account->bankConnection->user_id)
                ->where('source', BankConnectionAccount::SOURCE_ENABLE_BANKING)
                ->where('external_account_uid_hash', $account->external_account_uid_hash)
                ->whereNotNull('balance')
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->first();

            $computedBalance = $latestPosting?->balance;
        }

        if ($computedBalance === null || $expectedBalance === null) {
            return [
                'expected_balance' => $expectedBalance,
                'computed_balance' => $computedBalance,
                'balance_matches' => null,
            ];
        }

        return [
            'expected_balance' => $expectedBalance,
            'computed_balance' => $computedBalance,
            'balance_matches' => abs((float) $expectedBalance - (float) $computedBalance) <= 0.005,
        ];
    }

    private function recordFailedSync(BankSyncRun $run, BankConnectionAccount $account, Throwable $exception): void
    {
        $connectionStatus = ($exception instanceof RequestException && in_array($exception->response->status(), [400, 401, 403, 404], true))
            || $exception->getCode() === 401
            ? BankConnection::STATUS_RENEWAL_REQUIRED
            : BankConnection::STATUS_FAILED;
        $errorCode = $exception instanceof RequestException
            ? (string) ($exception->response->json('error') ?? $exception->response->json('code') ?? $exception->response->status())
            : $exception->getCode();

        DB::transaction(function () use ($run, $account, $exception, $connectionStatus, $errorCode): void {
            $account->update([
                'status' => $connectionStatus === BankConnection::STATUS_RENEWAL_REQUIRED
                    ? BankConnectionAccount::STATUS_RENEWAL_REQUIRED
                    : $account->status,
            ]);

            $account->bankConnection->update([
                'status' => $connectionStatus,
                'last_failed_sync_at' => now(),
                'failure_code' => (string) $errorCode,
                'failure_message' => $exception->getMessage(),
            ]);

            $run->update([
                'status' => BankSyncRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_code' => (string) $errorCode,
                'error_message' => $exception->getMessage(),
                'raw_error' => [
                    'class' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
            ]);
        });
    }
}
