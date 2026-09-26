<?php

namespace App\Http\Controllers;

use App\Finance\BalanceChainInspector;
use App\Finance\EnableBanking\EnableBankingClient;
use App\Http\Requests\StoreBankAccountRequest;
use App\Http\Requests\UpdateBankAccountRequest;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\BankSyncRun;
use App\Models\Category;
use App\Models\Posting;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        return Inertia::render('bank-accounts/index', [
            'accounts' => $accounts->map(fn (BankAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'bank_key' => $account->bank_key,
                'bank_name' => $account->bank_name,
                'currency' => $account->currency,
                'current_balance' => $account->current_balance === null ? null : (float) $account->current_balance,
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
            'availableBanks' => $this->availableBanks($enableBanking),
            'defaults' => [
                'aspsp_name' => config('services.enable_banking.aspsp_name'),
                'aspsp_country' => config('services.enable_banking.aspsp_country'),
            ],
        ]);
    }

    public function show(Request $request, BankAccount $bankAccount, BalanceChainInspector $inspector): Response
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
                'amount' => (float) $posting->amount,
                'balance' => $posting->balance === null ? null : (float) $posting->balance,
                'currency' => $posting->currency,
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
        $balanceAudit = $isManual ? null : $inspector->inspect($bankAccount);
        $balanceDelta = $balanceAudit['total_delta'] ?? null;

        return Inertia::render('bank-accounts/show', [
            'account' => [
                'id' => $bankAccount->id,
                'name' => $bankAccount->name,
                'bank_name' => $bankAccount->bank_name,
                'currency' => $bankAccount->currency,
                'account_type' => $bankAccount->account_type,
                'current_balance' => $bankAccount->current_balance === null ? null : (float) $bankAccount->current_balance,
                'last_known_posting_date' => $bankAccount->last_known_posting_date?->toDateString(),
                'last_updated_at' => $bankAccount->last_updated_at?->toDateTimeString(),
                'is_active' => $bankAccount->is_active,
                'is_favorite' => $bankAccount->is_favorite,
                'is_manual' => $isManual,
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
                'balance_diff' => $balanceDelta === '0.00' ? null : $balanceDelta,
            ],
            'postings' => $postings,
            'balanceAudit' => Inertia::optional(fn (): ?array => $balanceAudit),
            'syncRuns' => $this->syncRunsPayload($bankAccount->syncRuns()->latest('id')->limit(5)->get()),
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
        $request->user()->bankAccounts()->create([
            ...$request->validated(),
            'legacy_id' => BankAccount::MANUAL_LEGACY_ID_PREFIX.Str::uuid()->toString(),
        ]);

        return back();
    }

    public function update(UpdateBankAccountRequest $request, BankAccount $bankAccount): RedirectResponse
    {
        $bankAccount->update($request->validated());

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
     * Build the "My banks" side-panel payload: one entry per bank connection with its
     * status, expiry, latest sync and reconciliation state, and its remote accounts.
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
                $latestRun = $connection->syncRuns()->latest('id')->first();

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
                    'balance_mismatch' => $latestRun === null || ! $latestRun->hasBalanceMismatch() ? null : [
                        'delta' => $latestRun->balanceDelta(),
                        'currency' => $latestRun->bankConnectionAccount?->currency,
                    ],
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
                'balance_matches' => $run->balance_matches,
                'balance_delta' => $run->balanceDelta(),
            ])
            ->all();
    }

    /**
     * @return list<array{key:string,name:string,country:string}>
     */
    private function availableBanks(EnableBankingClient $enableBanking): array
    {
        $defaultCountry = (string) config('services.enable_banking.aspsp_country', 'DK');

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
            $country = (string) config('services.enable_banking.aspsp_country');

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
