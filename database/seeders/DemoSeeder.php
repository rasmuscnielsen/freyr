<?php

namespace Database\Seeders;

use App\Actions\RecalculateAccountBalance;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\BankSyncRun;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Seeds a demo user (john@example.com) with fictional accounts, a bank
 * connection, roughly a year of postings, and sync-run history. Expects the
 * default category tree to be seeded first (see DefaultCategoriesSeeder).
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john@example.com',
        ]);

        $subcategories = $this->defaultSubcategoriesByKey();
        $accounts = $this->createAccounts($user);
        $connectionAccounts = $this->createBankConnection($user, $accounts);

        $this->createPostings($accounts, $subcategories);
        $this->createSyncRuns($connectionAccounts);
        $this->refreshAccountBalances($accounts);
    }

    /**
     * @return Collection<string, Subcategory>
     */
    private function defaultSubcategoriesByKey(): Collection
    {
        return Subcategory::query()
            ->where('legacy_id', 'like', DefaultCategoriesSeeder::SUBCATEGORY_LEGACY_ID_PREFIX.'%')
            ->get()
            ->keyBy(fn (Subcategory $subcategory): string => Str::after(
                $subcategory->legacy_id,
                DefaultCategoriesSeeder::SUBCATEGORY_LEGACY_ID_PREFIX,
            ));
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
                ->withBalance(2358.42)
                ->create([
                    'legacy_id' => 'seed-account-everyday',
                    'name' => 'Everyday account',
                    'bank_key' => 'fjord_bank',
                    'bank_name' => 'Fjord Bank',
                    'currency' => 'EUR',
                    'account_type' => 'Consumption',
                    'account_number' => '5479123456',
                    'opening_balance' => null,
                ]),
            'budget' => BankAccount::factory()
                ->for($user)
                ->withBalance(1862.12)
                ->create([
                    'legacy_id' => 'seed-account-budget',
                    'name' => 'Bills account',
                    'bank_key' => 'fjord_bank',
                    'bank_name' => 'Fjord Bank',
                    'currency' => 'EUR',
                    'account_type' => 'Budget',
                    'account_number' => '5479654321',
                    'opening_balance' => null,
                ]),
            'savings' => BankAccount::factory()
                ->for($user)
                ->withBalance(9275.00)
                ->create([
                    'legacy_id' => 'seed-account-savings',
                    'name' => 'Emergency fund',
                    'bank_key' => 'summit_savings',
                    'bank_name' => 'Summit Savings',
                    'currency' => 'EUR',
                    'account_type' => 'Savings',
                    'account_number' => '6677000123',
                    'opening_balance' => '3675.00',
                ]),
            'travel' => BankAccount::factory()
                ->for($user)
                ->inactive()
                ->withBalance(0.00)
                ->create([
                    'legacy_id' => 'seed-account-travel',
                    'name' => 'Old travel card',
                    'bank_key' => 'nomad',
                    'bank_name' => 'Nomad',
                    'currency' => 'EUR',
                    'account_type' => 'Consumption',
                    'account_number' => '8811223344',
                    'opening_balance' => '215.00',
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
            ->forBank('Fjord Bank')
            ->create([
                'authorized_at' => now()->subDays(18),
                'access_valid_until' => now()->addDays(72),
                'last_successful_sync_at' => now()->subHours(3),
            ]);

        $linkedAccounts = collect([
            'everyday' => [
                'details' => 'Everyday',
                'last_four' => '3456',
                'product' => 'Current Account',
            ],
            'budget' => [
                'details' => 'Bills',
                'last_four' => '4321',
                'product' => 'Budget Account',
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
                        'currency' => 'EUR',
                        'iban' => 'DE89'.fake()->numerify('##########').$remoteAccount['last_four'],
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
                'currency' => 'EUR',
                'iban' => 'DE89000000000099999',
                'account_number' => '5479009999',
                'account_number_last_four' => '9999',
                'product' => 'Savings Account',
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
        $sequences = [
            'everyday' => 1,
            'budget' => 1,
            'savings' => 1,
            'travel' => 1,
        ];

        foreach (range(11, 0) as $monthOffset) {
            $month = $endMonth->subMonths($monthOffset);
            $monthIndex = 11 - $monthOffset;

            $this->post($accounts['everyday'], $subcategories['salary'], $month->day(1), 'Salary from Freyr Labs', 3850.00, $sequences);
            $this->post($accounts['everyday'], $subcategories['emergency_fund'], $month->day(2), 'Transfer to emergency fund', -400.00, $sequences);
            $this->post($accounts['savings'], $subcategories['internal_transfer'], $month->day(2), 'Transfer from everyday account', 400.00, $sequences);
            $this->post($accounts['everyday'], $subcategories['internal_transfer'], $month->day(2), 'Transfer to bills account', -1450.00, $sequences);
            $this->post($accounts['budget'], $subcategories['internal_transfer'], $month->day(2), 'Transfer from everyday account', 1450.00, $sequences);

            $this->post($accounts['budget'], $subcategories['rent'], $month->day(3), 'Apartment rent', -1180.00, $sequences);
            $this->post($accounts['budget'], $subcategories['utilities'], $month->day(5), 'Electricity and heating', -$this->monthlyAmount([95.40, 128.10, 84.60, 141.25], $monthIndex), $sequences);
            $this->post($accounts['budget'], $subcategories['internet'], $month->day(7), 'Fiber internet', -39.00, $sequences);
            $this->post($accounts['budget'], $subcategories['insurance'], $month->day(8), 'Home insurance', -32.50, $sequences);
            $this->post($accounts['everyday'], $subcategories['phone'], $month->day(9), 'Mobile subscription', -19.00, $sequences);

            foreach ([6, 13, 20, 27] as $index => $day) {
                $this->post($accounts['everyday'], $subcategories['groceries'], $this->dateInMonth($month, $day), $this->monthlyMerchant(['Fresh Market', 'Lidl', 'Green Grocer', 'Corner Deli'], $monthIndex + $index), -$this->monthlyAmount([52.20, 68.40, 83.85, 61.10, 90.55], $monthIndex + $index), $sequences);
            }

            $this->post($accounts['everyday'], $subcategories['transport'], $month->day(10), 'Metro card top-up', -49.00, $sequences);
            $this->post($accounts['everyday'], $subcategories['restaurants'], $month->day(14), $this->monthlyMerchant(['Trattoria Roma', 'Burger Collective', 'Sushi House'], $monthIndex), -$this->monthlyAmount([48.00, 36.50, 72.50, 51.00], $monthIndex), $sequences);
            $this->post($accounts['everyday'], $subcategories['takeaway'], $month->day(16), 'Pizza delivery', -$this->monthlyAmount([24.50, 31.00, 19.75], $monthIndex), $sequences);
            $this->post($accounts['everyday'], $subcategories['coffee'], $month->day(18), 'Café Luna', -6.40, $sequences);
            $this->post($accounts['everyday'], $subcategories['streaming'], $month->day(21), 'Netflix', -12.99, $sequences);
            $this->post($accounts['everyday'], $subcategories['subscriptions'], $month->day(21), 'Spotify', -10.99, $sequences);
            $this->post($accounts['everyday'], $subcategories['fitness'], $month->day(2), 'FitLife gym membership', -29.90, $sequences);
            $this->post($accounts['everyday'], $subcategories['pharmacy'], $month->day(22), 'City Pharmacy', -$this->monthlyAmount([8.80, 16.40, 12.60, 21.40], $monthIndex), $sequences);

            if ($monthIndex % 3 === 0) {
                $this->post($accounts['everyday'], $subcategories['clothing'], $month->day(24), $this->monthlyMerchant(['Zara', 'Uniqlo', 'H&M'], $monthIndex), -$this->monthlyAmount([64.00, 92.00, 38.00], $monthIndex), $sequences);
            }

            if ($monthIndex % 4 === 1) {
                $this->post($accounts['everyday'], $subcategories['home'], $month->day(25), 'IKEA', -$this->monthlyAmount([42.00, 112.00, 67.50], $monthIndex), $sequences);
            }

            if ($monthIndex % 5 === 2) {
                $this->post($accounts['everyday'], $subcategories['doctor'], $month->day(15), 'Dental check-up', -85.00, $sequences);
            }

            $this->post($accounts['everyday'], $subcategories['investments'], $month->day(28), 'Monthly index fund deposit', -250.00, $sequences);
        }

        $this->post($accounts['everyday'], $subcategories['electronics'], $endMonth->day(17), 'Laptop repair', -389.90, $sequences, ['is_extraordinary' => true]);
        $this->post($accounts['everyday'], null, $endMonth->day(29), 'PayPal payment 4312', -22.80, $sequences, ['categorized_by' => null]);

        $this->post($accounts['travel'], $subcategories['travel'], $endMonth->subMonths(10)->day(12), 'Flight to Lisbon', -138.00, $sequences);
        $this->post($accounts['travel'], $subcategories['restaurants'], $endMonth->subMonths(10)->day(13), 'Lisbon dinner', -77.00, $sequences);
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
        foreach ($accounts as $account) {
            if ($account->opening_balance !== null && ! $account->hasBankConnection()) {
                app(RecalculateAccountBalance::class)->handle($account);

                continue;
            }

            $account->update(['last_known_posting_date' => $account->postings()->max('date')]);
        }
    }

    /**
     * @param  array<string, int>  $sequences
     * @param  array<string, mixed>  $overrides
     */
    private function post(
        BankAccount $account,
        ?Subcategory $subcategory,
        CarbonImmutable $date,
        string $description,
        float $amount,
        array &$sequences,
        array $overrides = [],
    ): Posting {
        $accountKey = Str::after($account->legacy_id, 'seed-account-');
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
            'external_posting_id' => "seed-{$accountKey}-{$sequence}",
            'date' => $date->toDateString(),
            'description' => $description,
            'original_description' => strtoupper($description),
            'amount' => $amount,
            'original_amount' => $amount,
            'currency' => $account->currency,
            'original_currency' => $account->currency,
            'country_code' => null,
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
}
