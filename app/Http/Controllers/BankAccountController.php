<?php

namespace App\Http\Controllers;

use App\Finance\EnableBanking\EnableBankingClient;
use App\Http\Requests\StoreBankAccountRequest;
use App\Http\Requests\UpdateBankAccountRequest;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
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
            ->withCount('postings')
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
                'last_booked_balance' => $account->last_booked_balance === null ? null : (float) $account->last_booked_balance,
                'last_available_balance' => $account->last_available_balance === null ? null : (float) $account->last_available_balance,
                'account_type' => $account->account_type,
                'account_subcategory_key' => $account->account_subcategory_key,
                'last_known_posting_date' => $account->last_known_posting_date?->toDateString(),
                'is_active' => $account->is_active,
                'is_favorite' => $account->is_favorite,
                'postings_count' => $account->postings_count,
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
            'availableBanks' => $this->availableBanks($enableBanking),
            'defaults' => [
                'aspsp_name' => config('services.enable_banking.aspsp_name'),
                'aspsp_country' => config('services.enable_banking.aspsp_country'),
            ],
        ]);
    }

    public function store(StoreBankAccountRequest $request): RedirectResponse
    {
        $request->user()->bankAccounts()->create([
            ...$request->validated(),
            'legacy_id' => 'manual-'.Str::uuid()->toString(),
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

        if ($bankAccount->postings()->exists()) {
            $bankAccount->update(['is_active' => false]);

            return back()->with('status', 'Accounts with postings are archived instead of deleted.');
        }

        $bankAccount->delete();

        return back();
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
