<?php

namespace App\Http\Controllers;

use App\Finance\AccountBalanceRecalculator;
use App\Finance\EnableBanking\EnableBankingClient;
use App\Finance\Fx\ExchangeRateStore;
use App\Finance\MoneyMath;
use App\Http\Requests\StoreBankAccountRequest;
use App\Http\Requests\UpdateBankAccountRequest;
use App\Jobs\ConvertReportingAmountsJob;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\BankSyncRun;
use App\Models\Category;
use App\Models\Posting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class BankAccountController extends Controller
{
    public function index(Request $request, EnableBankingClient $enableBanking): Response
    {
        $user = $request->user();
        $accounts = BankAccount::query()
            ->whereBelongsTo($user)
            ->with('bankConnectionAccounts.bankConnection')
            ->withMax('postings', 'date')
            ->orderByDesc('is_active')
            ->orderBy('bank_name')
            ->orderBy('name')
            ->get();

        $reportingBalances = $this->reportingBalances($user, $accounts);

        return Inertia::render('bank-accounts/index', [
            'accounts' => $accounts->map(fn (BankAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'bank_key' => $account->bank_key,
                'bank_name' => $account->bank_name,
                'currency' => $account->currency,
                'current_balance' => $account->current_balance === null ? null : (float) $account->current_balance,
                'opening_balance' => $account->opening_balance === null ? null : (float) $account->opening_balance,
                'reporting_balance' => $reportingBalances[$account->id] ?? null,
                'last_known_posting_date' => $account->last_known_posting_date?->toDateString(),
                'is_active' => $account->is_active,
                'is_favorite' => $account->is_favorite,
                'is_spiir_imported' => $account->isSpiirImported(),
                'postings_max_date' => $account->postings_max_date,
                'connection_accounts' => $account->bankConnectionAccounts->map(fn (BankConnectionAccount $connectionAccount): array => [
                    'id' => $connectionAccount->id,
                    'status' => $connectionAccount->status,
                    'name' => $connectionAccount->details ?? $connectionAccount->name,
                    'account_number_last_four' => $connectionAccount->account_number_last_four,
                    'last_synced_at' => $connectionAccount->last_synced_at?->toDateTimeString(),
                    'last_transaction_date' => $connectionAccount->last_transaction_date?->toDateString(),
                    'needs_renewal' => $connectionAccount->status === BankConnectionAccount::STATUS_RENEWAL_REQUIRED
                        || $connectionAccount->bankConnection->needsRenewal(),
                    'connection' => [
                        'id' => $connectionAccount->bankConnection->id,
                        'status' => $connectionAccount->bankConnection->status,
                        'aspsp_name' => $connectionAccount->bankConnection->aspsp_name,
                        'aspsp_country' => $connectionAccount->bankConnection->aspsp_country,
                        'needs_renewal' => $connectionAccount->bankConnection->needsRenewal(),
                    ],
                ]),
            ]),
            'linkableConnectionAccounts' => BankConnectionAccount::query()
                ->where('user_id', $user->id)
                ->whereNull('bank_account_id')
                ->where('status', BankConnectionAccount::STATUS_UNLINKED)
                ->whereHas('bankConnection', fn ($query) => $query->where('status', BankConnection::STATUS_ACTIVE))
                ->with('bankConnection')
                ->orderBy('details')
                ->orderBy('name')
                ->get()
                ->map(fn (BankConnectionAccount $connectionAccount): array => [
                    'id' => $connectionAccount->id,
                    'status' => $connectionAccount->status,
                    'name' => $connectionAccount->details ?? $connectionAccount->name,
                    'details' => $connectionAccount->details,
                    'currency' => $connectionAccount->currency,
                    'account_number_last_four' => $connectionAccount->account_number_last_four,
                    'product' => $connectionAccount->product,
                    'connection' => [
                        'id' => $connectionAccount->bankConnection->id,
                        'aspsp_name' => $connectionAccount->bankConnection->aspsp_name,
                        'aspsp_country' => $connectionAccount->bankConnection->aspsp_country,
                    ],
                ]),
            'connections' => $this->connectionsPayload($user),
            'setupConnectionId' => $this->setupConnectionId($request),
            'availableBanks' => $this->availableBanks($enableBanking, $user),
            'currencies' => config('regions.ecb_currencies'),
            'defaults' => [
                'aspsp_name' => config('services.enable_banking.aspsp_name'),
                // Bank selection defaults to the user's country but may differ
                // for foreign banks — the connection stores its own value.
                'aspsp_country' => $user->country_code ?? config('services.enable_banking.aspsp_country'),
            ],
        ]);
    }

    public function show(Request $request, BankAccount $bankAccount): Response
    {
        abort_unless($request->user()?->is($bankAccount->user), 403);

        $bankAccount->loadCount('postings');
        $bankAccount->load('bankConnectionAccounts.bankConnection');

        $postings = $bankAccount->postings()
            ->with(['categorizedSubcategory.category'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (Posting $posting): array => [
                'id' => $posting->id,
                'date' => $posting->date?->toDateString(),
                'description' => $posting->description,
                // The account page shows account-currency amounts — the
                // original pair, not the reporting conversion.
                'original_amount' => $posting->original_amount === null ? null : (float) $posting->original_amount,
                'original_currency' => $posting->original_currency ?? $bankAccount->currency,
                'balance' => $posting->balance === null ? null : (float) $posting->balance,
                'comment' => $posting->comment,
                'is_extraordinary' => $posting->is_extraordinary,
                'categorized_subcategory_id' => $posting->categorized_subcategory_id,
                'category' => $posting->categorizedSubcategory?->category === null ? null : [
                    'name' => $posting->categorizedSubcategory->category->name,
                    'subcategory' => $posting->categorizedSubcategory->name,
                ],
            ]);

        $connectionAccount = $bankAccount->bankConnectionAccounts->first();
        $connection = $connectionAccount?->bankConnection;
        $isManual = ! $bankAccount->hasBankConnection();

        return Inertia::render('bank-accounts/show', [
            'account' => [
                'id' => $bankAccount->id,
                'name' => $bankAccount->name,
                'bank_name' => $bankAccount->bank_name,
                'currency' => $bankAccount->currency,
                'account_type' => $bankAccount->account_type,
                'current_balance' => $bankAccount->current_balance === null ? null : (float) $bankAccount->current_balance,
                'opening_balance' => $bankAccount->opening_balance === null ? null : (float) $bankAccount->opening_balance,
                'last_known_posting_date' => $bankAccount->last_known_posting_date?->toDateString(),
                'last_updated_at' => $bankAccount->last_updated_at?->toDateTimeString(),
                'is_active' => $bankAccount->is_active,
                'is_favorite' => $bankAccount->is_favorite,
                'is_manual' => $isManual,
                'provides_running_balance' => $bankAccount->providesRunningBalance(),
                'postings_count' => $bankAccount->postings_count,
                'last_synced_at' => $connectionAccount?->last_synced_at?->toDateTimeString(),
                'connection' => $connection === null ? null : [
                    'id' => $connection->id,
                    'status' => $connection->status,
                    'aspsp_name' => $connection->aspsp_name,
                    'aspsp_country' => $connection->aspsp_country,
                    'needs_renewal' => $connection->needsRenewal(),
                    'access_valid_until' => $connection->access_valid_until?->toIso8601String(),
                ],
            ],
            'postings' => $postings,
            'syncRuns' => $this->syncRunsPayload(BankSyncRun::query()
                ->where(function ($query) use ($bankAccount): void {
                    $query->where('bank_account_id', $bankAccount->id)
                        ->orWhereIn('bank_connection_account_id', $bankAccount->bankConnectionAccounts->pluck('id'));
                })
                ->latest('id')
                ->limit(5)
                ->get()),
            'categories' => Category::query()
                ->with(['subcategories' => fn ($query) => $query->orderBy('name')])
                ->orderBy('name')
                ->get()
                ->sortBy(fn (Category $category): int => $category->type->displayOrder())
                ->values()
                ->map(fn (Category $category): array => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'type' => $category->type,
                    'subcategories' => $category->subcategories->map(fn ($subcategory): array => [
                        'id' => $subcategory->id,
                        'name' => $subcategory->name,
                    ]),
                ]),
        ]);
    }

    public function store(StoreBankAccountRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $openingBalance = MoneyMath::round((string) ($validated['opening_balance'] ?? '0.00'));

        $account = $request->user()->bankAccounts()->create([
            ...$validated,
            'legacy_id' => BankAccount::MANUAL_LEGACY_ID_PREFIX.Str::uuid()->toString(),
            'opening_balance' => $openingBalance,
            'current_balance' => $openingBalance,
        ]);

        $this->dispatchConversionForForeignAccount($account, $request->user());

        return back();
    }

    public function update(UpdateBankAccountRequest $request, BankAccount $bankAccount, AccountBalanceRecalculator $balanceRecalculator): RedirectResponse
    {
        $validated = $request->validated();

        if (array_key_exists('opening_balance', $validated)) {
            if ($bankAccount->hasBankConnection()) {
                throw ValidationException::withMessages([
                    'opening_balance' => 'An opening balance can only be set for an account without a bank connection.',
                ]);
            }

            $validated['opening_balance'] = MoneyMath::round((string) $validated['opening_balance']);
        }

        // The account currency denominates the whole balance chain — it must
        // not move once postings or a live feed exist.
        if (array_key_exists('currency', $validated)
            && $validated['currency'] !== $bankAccount->currency
            && ($bankAccount->hasBankConnection() || $bankAccount->postings()->exists())) {
            throw ValidationException::withMessages([
                'currency' => 'The account currency cannot change once the account has postings or a bank connection.',
            ]);
        }

        $openingBalanceChanged = array_key_exists('opening_balance', $validated)
            && $bankAccount->opening_balance !== $validated['opening_balance'];
        $currencyChanged = array_key_exists('currency', $validated)
            && $validated['currency'] !== $bankAccount->currency;

        $bankAccount->update($validated);

        if ($openingBalanceChanged) {
            $balanceRecalculator->recalculate($bankAccount);
        }

        if ($openingBalanceChanged || $currencyChanged) {
            $this->dispatchConversionForForeignAccount($bankAccount, $request->user());
        }

        return back();
    }

    public function destroy(Request $request, BankAccount $bankAccount): RedirectResponse
    {
        abort_unless($request->user()?->is($bankAccount->user), 403);

        DB::transaction(function () use ($bankAccount): void {
            // Linked remote accounts go back to the connection's setup list —
            // their bank_account_id is nulled by the FK once the account is
            // gone, and postings cascade away with the account.
            $bankAccount->bankConnectionAccounts()
                ->update(['status' => BankConnectionAccount::STATUS_UNLINKED]);

            $bankAccount->delete();
        });

        return back();
    }

    /**
     * A foreign-currency balance needs a cached valuation rate before it can
     * contribute to the reporting total — queue the conversion job so the
     * rate is fetched instead of the account showing up unvalued.
     */
    private function dispatchConversionForForeignAccount(BankAccount $account, User $user): void
    {
        if ($user->reporting_currency === null
            || $account->currency === $user->reporting_currency
            || $account->current_balance === null) {
            return;
        }

        ConvertReportingAmountsJob::dispatch($user->id)->afterCommit();
    }

    /**
     * Each account's current balance valued in the user's reporting currency,
     * keyed by account id. Same-currency balances pass through untouched;
     * foreign ones convert at the newest cached rate — never by calling the
     * provider while rendering. Null means the balance cannot be valued (no
     * reporting currency, no balance, or no cached rate), so the frontend can
     * fall back to per-currency subtotals.
     *
     * @param  Collection<int, BankAccount>  $accounts
     * @return array<int, float|null>
     */
    private function reportingBalances(User $user, Collection $accounts): array
    {
        $quote = $user->reporting_currency;
        $rates = app(ExchangeRateStore::class);
        $today = CarbonImmutable::today();
        $ratesByBase = [];

        return $accounts
            ->mapWithKeys(function (BankAccount $account) use ($quote, $rates, $today, &$ratesByBase): array {
                if ($account->current_balance === null || $quote === null) {
                    return [$account->id => null];
                }

                if ($account->currency === $quote) {
                    return [$account->id => (float) $account->current_balance];
                }

                if (! array_key_exists($account->currency, $ratesByBase)) {
                    $ratesByBase[$account->currency] = $rates->rateFor($account->currency, $quote, $today);
                }

                $rate = $ratesByBase[$account->currency];

                return [
                    $account->id => $rate === null
                        ? null
                        : (float) MoneyMath::round(bcmul((string) $account->current_balance, $rate['rate'], 20)),
                ];
            })
            ->all();
    }

    /**
     * Build the "My banks" side-panel payload with connection state and accounts.
     *
     * @return list<array<string, mixed>>
     */
    private function connectionsPayload(User $user): array
    {
        return $user->bankConnections()
            ->with(['accounts.bankAccount', 'accounts.bankConnection'])
            ->where('status', '!=', BankConnection::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (BankConnection $connection): array {
                return [
                    'id' => $connection->id,
                    'status' => $connection->status,
                    'aspsp_name' => $connection->aspsp_name,
                    'aspsp_country' => $connection->aspsp_country,
                    'access_valid_until' => $connection->access_valid_until?->toIso8601String(),
                    'last_successful_sync_at' => $connection->last_successful_sync_at?->toDateTimeString(),
                    'failure_code' => null,
                    'failure_message' => match ($connection->status) {
                        BankConnection::STATUS_FAILED => BankSyncRun::GENERIC_ERROR_MESSAGE,
                        BankConnection::STATUS_RENEWAL_REQUIRED => 'The bank connection needs renewal.',
                        default => null,
                    },
                    'needs_renewal' => $connection->needsRenewal(),
                    'linked_accounts_count' => $connection->accounts
                        ->where('status', BankConnectionAccount::STATUS_LINKED)
                        ->whereNotNull('bank_account_id')
                        ->count(),
                    'accounts' => $connection->accounts
                        ->sortBy(fn (BankConnectionAccount $account): string => $account->details ?? $account->name)
                        ->values()
                        ->map(fn (BankConnectionAccount $account): array => [
                            'id' => $account->id,
                            'status' => $account->status,
                            'name' => $account->details ?? $account->name,
                            'currency' => $account->currency,
                            'product' => $account->product,
                            'account_number_last_four' => $account->account_number_last_four,
                            'last_synced_at' => $account->last_synced_at?->toDateTimeString(),
                            'last_transaction_date' => $account->last_transaction_date?->toDateString(),
                            'bank_account' => $account->bankAccount === null ? null : [
                                'id' => $account->bankAccount->id,
                                'name' => $account->bankAccount->name,
                                'is_active' => $account->bankAccount->is_active,
                            ],
                        ])
                        ->all(),
                ];
            })
            ->all();
    }

    /**
     * Id of a freshly connected bank connection whose accounts still need to be
     * mapped, passed through so the index page can open the setup wizard.
     */
    private function setupConnectionId(Request $request): ?int
    {
        $connectionId = $request->integer('setup') ?: null;

        if ($connectionId === null) {
            return null;
        }

        return BankConnection::query()
            ->whereKey($connectionId)
            ->whereBelongsTo($request->user())
            ->exists()
            ? $connectionId
            : null;
    }

    /**
     * @param  Collection<int, BankSyncRun>  $runs
     * @return list<array<string, mixed>>
     */
    private function syncRunsPayload(Collection $runs): array
    {
        return $runs
            ->map(fn (BankSyncRun $run): array => [
                'id' => $run->id,
                'status' => $run->status,
                'started_at' => $run->started_at?->toDateTimeString(),
                'date_from' => $run->date_from?->toDateString(),
                'date_to' => $run->date_to?->toDateString(),
                'new_postings_count' => $run->new_postings_count,
                'updated_postings_count' => $run->updated_postings_count,
                'duplicate_postings_count' => $run->duplicate_postings_count,
                'error_code' => null,
                'error_message' => match (true) {
                    $run->status === BankSyncRun::STATUS_SKIPPED => 'The bank account was unlinked before sync completed.',
                    $run->status === BankSyncRun::STATUS_FAILED && $run->error_code === 'account_archived' => 'The bank account is archived.',
                    $run->status === BankSyncRun::STATUS_FAILED && $run->error_code === 'connection_archived' => 'The bank connection is archived.',
                    $run->status === BankSyncRun::STATUS_FAILED => BankSyncRun::GENERIC_ERROR_MESSAGE,
                    default => null,
                },
            ])
            ->all();
    }

    /**
     * @return list<array{key:string,name:string,country:string}>
     */
    private function availableBanks(EnableBankingClient $enableBanking, User $user): array
    {
        $defaultCountry = $user->country_code ?? (string) config('services.enable_banking.aspsp_country', 'DK');

        try {
            return Cache::remember('enable-banking.aspsps.'.strtolower($defaultCountry).'.personal.ais', now()->addHours(12), function () use ($defaultCountry, $enableBanking): array {
                $response = $enableBanking->aspsps($defaultCountry);

                return collect(Arr::wrap($response['aspsps'] ?? $response))
                    ->filter(fn (mixed $bank): bool => is_array($bank))
                    ->map(fn (array $bank): array => [
                        'name' => (string) ($bank['name'] ?? data_get($bank, 'aspsp.name')),
                        'country' => (string) ($bank['country'] ?? data_get($bank, 'aspsp.country', $defaultCountry)),
                    ])
                    ->filter(fn (array $bank): bool => $bank['name'] !== '' && $bank['country'] !== '')
                    ->unique(fn (array $bank): string => $bank['country'].'|'.$bank['name'])
                    ->sortBy('name')
                    ->values()
                    ->map(fn (array $bank): array => [
                        ...$bank,
                        'key' => $bank['country'].'|'.$bank['name'],
                    ])
                    ->all();
            });
        } catch (Throwable) {
            $name = (string) config('services.enable_banking.aspsp_name');
            $country = $defaultCountry;

            if ($name === '' || $country === '') {
                return [];
            }

            return [[
                'key' => $country.'|'.$name,
                'name' => $name,
                'country' => $country,
            ]];
        }
    }
}
