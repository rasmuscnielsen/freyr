<?php

namespace Database\Seeders;

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\BankSyncRun;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $subcategories = $this->createCategories();
        $accounts = $this->createAccounts($user);
        $connectionAccounts = $this->createBankConnection($user, $accounts);

        $this->createPostings($accounts, $subcategories);
        $this->createSyncRuns($connectionAccounts);
        $this->refreshAccountBalances($accounts);
    }

    /**
     * @return Collection<string, Subcategory>
     */
    private function createCategories(): Collection
    {
        $subcategories = collect();

        foreach ($this->categoryDefinitions() as $categoryDefinition) {
            $category = Category::factory()->create([
                'legacy_id' => 'seed-category-'.$categoryDefinition['key'],
                'name' => $categoryDefinition['name'],
                'type' => $categoryDefinition['type'],
                'hyphenated_name' => Str::slug($categoryDefinition['name']),
            ]);

            foreach ($categoryDefinition['subcategories'] as $subcategoryDefinition) {
                $subcategories->put($subcategoryDefinition['key'], Subcategory::factory()
                    ->forCategory($category)
                    ->create([
                        'legacy_id' => 'seed-subcategory-'.$subcategoryDefinition['key'],
                        'name' => $subcategoryDefinition['name'],
                        'hyphenated_name' => Str::slug($subcategoryDefinition['name']),
                        'expense_type' => $subcategoryDefinition['expense_type'] ?? null,
                        'hints' => $subcategoryDefinition['hints'] ?? null,
                        'is_ignored' => $subcategoryDefinition['is_ignored'] ?? false,
                    ]));
            }
        }

        return $subcategories;
    }

    /**
     * @return Collection<string, BankAccount>
     */
    private function createAccounts(User $user): Collection
    {
        return collect([
            'everyday' => BankAccount::factory()
                ->for($user)
                ->favorite()
                ->withBalance(23580.42)
                ->create([
                    'legacy_id' => 'seed-account-everyday',
                    'name' => 'Everyday account',
                    'bank_key' => 'nykredit',
                    'bank_name' => 'Nykredit Bank',
                    'account_type' => 'Consumption',
                    'account_subcategory_key' => 'consumption',
                    'account_number' => '5479123456',
                    'owner_name' => $user->name,
                    'supports_payment_from' => true,
                    'supports_payment_to' => true,
                    'connection_type' => BankConnectionAccount::SOURCE_ENABLE_BANKING,
                ]),
            'budget' => BankAccount::factory()
                ->for($user)
                ->withBalance(18620.12)
                ->create([
                    'legacy_id' => 'seed-account-budget',
                    'name' => 'Bills account',
                    'bank_key' => 'nykredit',
                    'bank_name' => 'Nykredit Bank',
                    'account_type' => 'Budget',
                    'account_subcategory_key' => 'budget',
                    'account_number' => '5479654321',
                    'owner_name' => $user->name,
                    'supports_payment_from' => true,
                    'supports_payment_to' => true,
                    'connection_type' => BankConnectionAccount::SOURCE_ENABLE_BANKING,
                ]),
            'savings' => BankAccount::factory()
                ->for($user)
                ->withBalance(92750.00)
                ->create([
                    'legacy_id' => 'seed-account-savings',
                    'name' => 'Emergency fund',
                    'bank_key' => 'lunar',
                    'bank_name' => 'Lunar',
                    'account_type' => 'Savings',
                    'account_subcategory_key' => 'savings',
                    'account_number' => '6677000123',
                    'owner_name' => $user->name,
                    'supports_payment_to' => true,
                    'connection_type' => BankConnectionAccount::SOURCE_ENABLE_BANKING,
                ]),
            'travel' => BankAccount::factory()
                ->for($user)
                ->inactive()
                ->withBalance(0.00)
                ->create([
                    'legacy_id' => 'seed-account-travel',
                    'name' => 'Old travel card',
                    'bank_key' => 'wise',
                    'bank_name' => 'Wise',
                    'account_type' => 'Consumption',
                    'account_subcategory_key' => 'consumption',
                    'account_number' => '8811223344',
                    'owner_name' => $user->name,
                    'connection_type' => null,
                ]),
        ]);
    }

    /**
     * @param  Collection<string, BankAccount>  $accounts
     * @return Collection<string, BankConnectionAccount>
     */
    private function createBankConnection(User $user, Collection $accounts): Collection
    {
        $connection = BankConnection::factory()
            ->for($user)
            ->forBank('Nykredit Bank')
            ->create([
                'authorized_at' => now()->subDays(18),
                'access_valid_until' => now()->addDays(72),
                'last_successful_sync_at' => now()->subHours(3),
            ]);

        $linkedAccounts = collect([
            'everyday' => [
                'details' => 'Everyday',
                'last_four' => '3456',
                'product' => 'Nykredit Hverdag',
            ],
            'budget' => [
                'details' => 'Bills',
                'last_four' => '4321',
                'product' => 'Nykredit Budget',
            ],
        ])->mapWithKeys(function (array $remoteAccount, string $accountKey) use ($accounts, $connection, $user): array {
            $uid = 'seed-remote-'.$accountKey;

            return [
                $accountKey => BankConnectionAccount::factory()
                    ->for($connection, 'bankConnection')
                    ->linkedTo($accounts[$accountKey])
                    ->create([
                        'user_id' => $user->id,
                        'external_account_uid' => $uid,
                        'external_account_uid_hash' => hash('sha256', $uid),
                        'name' => $user->name,
                        'details' => $remoteAccount['details'],
                        'iban' => 'DK50'.fake()->numerify('####000000').$remoteAccount['last_four'],
                        'account_number' => '5479'.fake()->numerify('##').$remoteAccount['last_four'],
                        'account_number_last_four' => $remoteAccount['last_four'],
                        'product' => $remoteAccount['product'],
                        'last_synced_at' => now()->subHours(3),
                        'last_transaction_date' => CarbonImmutable::today()->startOfMonth()->subDay()->toDateString(),
                    ]),
            ];
        });

        $uid = 'seed-remote-savings';
        BankConnectionAccount::factory()
            ->for($connection, 'bankConnection')
            ->create([
                'user_id' => $user->id,
                'external_account_uid' => $uid,
                'external_account_uid_hash' => hash('sha256', $uid),
                'name' => $user->name,
                'details' => 'Savings',
                'iban' => 'DK5000000000099999',
                'account_number' => '5479009999',
                'account_number_last_four' => '9999',
                'product' => 'Nykredit Opsparing',
                'last_synced_at' => now()->subHours(3),
                'last_transaction_date' => CarbonImmutable::today()->startOfMonth()->subDay()->toDateString(),
            ]);

        return $linkedAccounts;
    }

    /**
     * @param  Collection<string, BankAccount>  $accounts
     * @param  Collection<string, Subcategory>  $subcategories
     */
    private function createPostings(Collection $accounts, Collection $subcategories): void
    {
        $today = CarbonImmutable::today();
        $endMonth = $today->startOfMonth()->subMonth();
        $balances = [
            'everyday' => 11420.00,
            'budget' => 8500.00,
            'savings' => 36750.00,
            'travel' => 2150.00,
        ];
        $sequences = [
            'everyday' => 1,
            'budget' => 1,
            'savings' => 1,
            'travel' => 1,
        ];

        foreach (range(11, 0) as $monthOffset) {
            $month = $endMonth->subMonths($monthOffset);
            $monthIndex = 11 - $monthOffset;

            $this->post($accounts['everyday'], $subcategories['salary'], $month->day(1), 'Salary from Freyr Labs', 44500.00, Posting::CATEGORY_TYPE_INCOME, $balances, $sequences);
            $this->post($accounts['everyday'], $subcategories['emergency_fund'], $month->day(2), 'Transfer to emergency fund', -6500.00, Posting::CATEGORY_TYPE_SAVING, $balances, $sequences);
            $this->post($accounts['savings'], $subcategories['internal_transfer'], $month->day(2), 'Transfer from everyday account', 6500.00, Posting::CATEGORY_TYPE_TRANSFER, $balances, $sequences);
            $this->post($accounts['everyday'], $subcategories['internal_transfer'], $month->day(2), 'Transfer to bills account', -14500.00, Posting::CATEGORY_TYPE_TRANSFER, $balances, $sequences);
            $this->post($accounts['budget'], $subcategories['internal_transfer'], $month->day(2), 'Transfer from everyday account', 14500.00, Posting::CATEGORY_TYPE_TRANSFER, $balances, $sequences);

            $this->post($accounts['budget'], $subcategories['rent'], $month->day(3), 'Apartment rent', -12500.00, Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences);
            $this->post($accounts['budget'], $subcategories['utilities'], $month->day(5), 'Electricity and heating', -$this->monthlyAmount([820, 940, 760, 1010], $monthIndex), Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences);
            $this->post($accounts['budget'], $subcategories['internet'], $month->day(7), 'Fiber internet', -299.00, Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences);
            $this->post($accounts['budget'], $subcategories['insurance'], $month->day(8), 'Home insurance', -460.00, Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences);
            $this->post($accounts['everyday'], $subcategories['phone'], $month->day(9), 'Mobile subscription', -149.00, Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences);

            foreach ([6, 13, 20, 27] as $index => $day) {
                $this->post($accounts['everyday'], $subcategories['groceries'], $this->dateInMonth($month, $day), $this->monthlyMerchant(['Netto', 'Rema 1000', 'Meny', 'Aarstiderne'], $monthIndex + $index), -$this->monthlyAmount([486.20, 612.40, 735.85, 558.10, 804.55], $monthIndex + $index), Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences);
            }

            $this->post($accounts['everyday'], $subcategories['transport'], $month->day(10), 'Rejsekort top-up', -450.00, Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences);
            $this->post($accounts['everyday'], $subcategories['restaurants'], $month->day(14), $this->monthlyMerchant(['Madklubben', 'Gasoline Grill', 'Bistro Royal'], $monthIndex), -$this->monthlyAmount([480, 365, 725, 510], $monthIndex), Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences);
            $this->post($accounts['everyday'], $subcategories['coffee'], $month->day(18), 'Coffee Collective', -74.00, Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences);
            $this->post($accounts['everyday'], $subcategories['streaming'], $month->day(21), 'Netflix', -129.00, Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences);
            $this->post($accounts['everyday'], $subcategories['pharmacy'], $month->day(22), 'Apoteket', -$this->monthlyAmount([88, 164, 126, 214], $monthIndex), Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences);

            if ($monthIndex % 3 === 0) {
                $this->post($accounts['everyday'], $subcategories['clothing'], $month->day(24), 'Arket', -$this->monthlyAmount([640, 920, 380], $monthIndex), Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences);
            }

            if ($monthIndex % 4 === 1) {
                $this->post($accounts['everyday'], $subcategories['home'], $month->day(25), 'IKEA', -$this->monthlyAmount([420, 1120, 675], $monthIndex), Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences);
            }

            $this->post($accounts['everyday'], $subcategories['investments'], $month->day(28), 'Monthly index fund deposit', -2000.00, Posting::CATEGORY_TYPE_SAVING, $balances, $sequences);
        }

        $this->post($accounts['everyday'], $subcategories['electronics'], $endMonth->day(17), 'MacBook repair', -3899.00, Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences, ['is_extraordinary' => true]);
        $this->post($accounts['everyday'], null, $endMonth->day(29), 'MobilePay 4312', -228.00, Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences, ['categorized_by' => null]);

        $this->post($accounts['travel'], $subcategories['travel'], $endMonth->subMonths(10)->day(12), 'SAS Copenhagen to Berlin', -1380.00, Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences);
        $this->post($accounts['travel'], $subcategories['restaurants'], $endMonth->subMonths(10)->day(13), 'Berlin dinner', -770.00, Posting::CATEGORY_TYPE_EXPENSE, $balances, $sequences);
    }

    /**
     * @param  Collection<string, BankConnectionAccount>  $connectionAccounts
     */
    private function createSyncRuns(Collection $connectionAccounts): void
    {
        foreach ($connectionAccounts as $account) {
            foreach ([2, 1, 0] as $monthOffset) {
                $startedAt = now()->startOfMonth()->subMonths($monthOffset)->addDays(1)->addHours(6);

                BankSyncRun::factory()
                    ->forConnectionAccount($account)
                    ->create([
                        'date_from' => $startedAt->copy()->subMonth()->startOfMonth()->toDateString(),
                        'date_to' => $startedAt->copy()->subDay()->toDateString(),
                        'started_at' => $startedAt,
                        'finished_at' => $startedAt->copy()->addSeconds(46),
                        'new_postings_count' => $monthOffset === 0 ? 9 : 21,
                        'updated_postings_count' => $monthOffset === 0 ? 1 : 0,
                        'duplicate_postings_count' => $monthOffset === 2 ? 2 : 0,
                    ]);
            }
        }
    }

    /**
     * @param  Collection<string, BankAccount>  $accounts
     */
    private function refreshAccountBalances(Collection $accounts): void
    {
        $accounts->each(function (BankAccount $account): void {
            $latestPosting = $account->postings()
                ->orderByDesc('date')
                ->orderByDesc('id')
                ->first();

            if ($latestPosting === null) {
                return;
            }

            $account->update([
                'last_booked_balance' => $latestPosting->balance,
                'last_available_balance' => $latestPosting->balance,
                'last_known_posting_date' => $latestPosting->date,
                'last_updated_at' => now(),
            ]);
        });
    }

    /**
     * @param  array<string, float>  $balances
     * @param  array<string, int>  $sequences
     * @param  array<string, mixed>  $overrides
     */
    private function post(
        BankAccount $account,
        ?Subcategory $subcategory,
        CarbonImmutable $date,
        string $description,
        float $amount,
        int $categoryType,
        array &$balances,
        array &$sequences,
        array $overrides = [],
    ): Posting {
        $accountKey = Str::after($account->legacy_id, 'seed-account-');
        $balances[$accountKey] += $amount;
        $sequence = $sequences[$accountKey]++;

        $factory = Posting::factory()->forAccount($account);

        if ($subcategory === null) {
            $factory = $factory->uncategorized();
        } else {
            $factory = $factory->categorizedAs($subcategory);
        }

        return $factory->create([
            'legacy_id' => "seed-posting-{$accountKey}-{$sequence}",
            'source' => 'seed',
            'external_account_uid_hash' => hash('sha256', "seed-remote-{$accountKey}"),
            'external_posting_id' => "seed-{$accountKey}-{$sequence}",
            'date' => $date->toDateString(),
            'description' => $description,
            'original_description' => strtoupper($description),
            'amount' => $amount,
            'original_amount' => $amount,
            'balance' => round($balances[$accountKey], 2),
            'category_type_code' => $categoryType,
            'currency' => $account->currency,
            'original_currency' => $account->currency,
            'country_code' => 'DK',
            'categorized_by' => $subcategory === null ? null : 'seed',
            'legacy_created_at' => $date->setTime(12, 0),
            ...$overrides,
        ]);
    }

    private function dateInMonth(CarbonImmutable $month, int $day): CarbonImmutable
    {
        return $month->day(min($day, $month->daysInMonth));
    }

    /**
     * @param  list<float>  $amounts
     */
    private function monthlyAmount(array $amounts, int $index): float
    {
        return $amounts[$index % count($amounts)];
    }

    /**
     * @param  list<string>  $merchants
     */
    private function monthlyMerchant(array $merchants, int $index): string
    {
        return $merchants[$index % count($merchants)];
    }

    /**
     * @return list<array{key:string,name:string,type:string,subcategories:list<array{key:string,name:string,expense_type?:string|null,hints?:string|null,is_ignored?:bool}>}>
     */
    private function categoryDefinitions(): array
    {
        return [
            [
                'key' => 'income',
                'name' => 'Income',
                'type' => 'Income',
                'subcategories' => [
                    ['key' => 'salary', 'name' => 'Salary'],
                    ['key' => 'reimbursements', 'name' => 'Reimbursements'],
                ],
            ],
            [
                'key' => 'housing',
                'name' => 'Housing',
                'type' => 'Expense',
                'subcategories' => [
                    ['key' => 'rent', 'name' => 'Rent', 'expense_type' => 'Fixed'],
                    ['key' => 'utilities', 'name' => 'Utilities', 'expense_type' => 'Fixed'],
                    ['key' => 'internet', 'name' => 'Internet', 'expense_type' => 'Fixed'],
                    ['key' => 'insurance', 'name' => 'Insurance', 'expense_type' => 'Fixed'],
                ],
            ],
            [
                'key' => 'food',
                'name' => 'Food',
                'type' => 'Expense',
                'subcategories' => [
                    ['key' => 'groceries', 'name' => 'Groceries', 'expense_type' => 'Variable'],
                    ['key' => 'restaurants', 'name' => 'Restaurants', 'expense_type' => 'Variable'],
                    ['key' => 'coffee', 'name' => 'Coffee', 'expense_type' => 'Variable'],
                ],
            ],
            [
                'key' => 'lifestyle',
                'name' => 'Lifestyle',
                'type' => 'Expense',
                'subcategories' => [
                    ['key' => 'streaming', 'name' => 'Streaming', 'expense_type' => 'Fixed'],
                    ['key' => 'pharmacy', 'name' => 'Pharmacy', 'expense_type' => 'Variable'],
                    ['key' => 'clothing', 'name' => 'Clothing', 'expense_type' => 'Variable'],
                    ['key' => 'home', 'name' => 'Home', 'expense_type' => 'Variable'],
                    ['key' => 'electronics', 'name' => 'Electronics', 'expense_type' => 'Variable'],
                    ['key' => 'travel', 'name' => 'Travel', 'expense_type' => 'Variable'],
                ],
            ],
            [
                'key' => 'transport',
                'name' => 'Transport',
                'type' => 'Expense',
                'subcategories' => [
                    ['key' => 'transport', 'name' => 'Public transport', 'expense_type' => 'Variable'],
                    ['key' => 'phone', 'name' => 'Phone', 'expense_type' => 'Fixed'],
                ],
            ],
            [
                'key' => 'saving',
                'name' => 'Saving',
                'type' => 'Saving',
                'subcategories' => [
                    ['key' => 'emergency_fund', 'name' => 'Emergency fund'],
                    ['key' => 'investments', 'name' => 'Investments'],
                ],
            ],
            [
                'key' => 'transfer',
                'name' => 'Transfers',
                'type' => 'Transfer',
                'subcategories' => [
                    ['key' => 'internal_transfer', 'name' => 'Internal transfer', 'is_ignored' => true],
                ],
            ],
        ];
    }
}
