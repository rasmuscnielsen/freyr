<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Build a small budget with income, a fixed bill, a variable expense, a saving,
 * an investment and an excluded transfer subcategory.
 *
 * @return array{user: User, account: BankAccount, salary: Subcategory, rent: Subcategory, groceries: Subcategory, savings: Subcategory, investment: Subcategory, transfer: Subcategory}
 */
function seedBudget(): array
{
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $incomeCategory = Category::factory()->income()->create(['name' => 'Income']);
    $expenseCategory = Category::factory()->create(['name' => 'Housing']);
    $savingsCategory = Category::factory()->saving()->create(['name' => 'Savings']);
    $investmentCategory = Category::factory()->investment()->create(['name' => 'Investments']);
    $transferCategory = Category::factory()->exclude()->create(['name' => 'Transfers']);
    $salary = Subcategory::factory()->forCategory($incomeCategory)->create(['name' => 'Salary']);
    $rent = Subcategory::factory()->forCategory($expenseCategory)->create([
        'name' => 'Rent',
        'expense_type' => 'Fixed',
    ]);
    $groceries = Subcategory::factory()->forCategory($expenseCategory)->create([
        'name' => 'Groceries',
        'expense_type' => 'Variable',
    ]);
    $savings = Subcategory::factory()->forCategory($savingsCategory)->create([
        'name' => 'Pension',
        'expense_type' => null,
    ]);
    $investment = Subcategory::factory()->forCategory($investmentCategory)->create([
        'name' => 'Index funds',
        'expense_type' => null,
    ]);
    $transfer = Subcategory::factory()->forCategory($transferCategory)->create([
        'name' => 'Internal transfer',
        'expense_type' => null,
    ]);

    return compact('user', 'account', 'salary', 'rent', 'groceries', 'savings', 'investment', 'transfer');
}

/**
 * Headers required to trigger a partial reload of the deferred `breakdown` prop.
 *
 * @return array<string, string>
 */
function breakdownPartialHeaders(): array
{
    $manifest = public_path('build/manifest.json');
    $version = file_exists($manifest) ? hash_file('xxh128', $manifest) : '';

    return [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'dashboard',
        'X-Inertia-Partial-Data' => 'breakdown',
    ];
}

test('dashboard aggregates income expenses using the months param for back-compat', function () {
    ['user' => $user, 'account' => $account, 'salary' => $salary, 'rent' => $rent, 'groceries' => $groceries, 'savings' => $savings, 'transfer' => $transfer] = seedBudget();

    Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
        ->create(['date' => '2026-01-05', 'amount' => 10000, 'original_amount' => 10000]);
    Posting::factory()->forAccount($account)->categorizedAs($rent)
        ->create(['date' => '2026-01-10', 'amount' => -1500, 'original_amount' => -1500]);
    Posting::factory()->forAccount($account)->categorizedAs($groceries)
        ->create(['date' => '2026-01-12', 'amount' => -1000, 'original_amount' => -1000]);
    // Excluded transfer: categorized under an Exclude category, omitted entirely.
    Posting::factory()->forAccount($account)->categorizedAs($transfer)
        ->create(['date' => '2026-01-13', 'amount' => 500000, 'original_amount' => 500000]);
    Posting::factory()->forAccount($account)->categorizedAs($savings)
        ->create(['date' => '2026-01-14', 'amount' => -3000, 'original_amount' => -3000]);
    // Extraordinary income is excluded from the overview.
    Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
        ->create(['date' => '2026-01-15', 'amount' => 20000, 'original_amount' => 20000, 'is_extraordinary' => true]);

    $this->actingAs($user)
        ->get(route('dashboard', ['months' => 3]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('period.selected', '12m')
            ->where('period.months', 3)
            ->where('summary.income', 10000)
            ->where('summary.bills', 1500)
            ->where('summary.consumption', 1000)
            ->where('summary.savings', 3000)
            ->where('summary.investments', 0)
            ->where('summary.uncategorized', 0)
            ->where('summary.in', 10000)
            ->where('summary.out', 5500)
            ->where('summary.result', 4500));
});

test('a refund categorized under an expense category nets against the bucket instead of inflating it', function () {
    ['user' => $user, 'account' => $account, 'groceries' => $groceries] = seedBudget();

    Posting::factory()->forAccount($account)->categorizedAs($groceries)
        ->create(['date' => '2026-01-10', 'amount' => -500, 'original_amount' => -500]);
    // The purchase is returned: a positive posting in the same expense subcategory.
    Posting::factory()->forAccount($account)->categorizedAs($groceries)
        ->create(['date' => '2026-01-20', 'amount' => 300, 'original_amount' => 300]);

    $this->actingAs($user)
        ->get(route('dashboard', ['months' => 3]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.consumption', 200)
            ->where('summary.out', 200)
            ->where('summary.result', -200));
});

test('period options include 12m, the all-years comparison and the years from earliest posting to now', function () {
    CarbonImmutable::setTestNow('2026-07-01 12:00:00');

    try {
        ['user' => $user, 'account' => $account, 'salary' => $salary] = seedBudget();

        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2024-03-05', 'amount' => 100, 'original_amount' => 100]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.selected', '12m')
                ->where('period.options', ['years', '12m', '2026', '2025', '2024']));
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('the years period compares yearly buckets over every year with data', function () {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00');

    try {
        ['user' => $user, 'account' => $account, 'salary' => $salary, 'groceries' => $groceries] = seedBudget();

        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2024-05-05', 'amount' => 8000, 'original_amount' => 8000]);
        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2025-05-05', 'amount' => 12000, 'original_amount' => 12000]);
        Posting::factory()->forAccount($account)->categorizedAs($groceries)
            ->create(['date' => '2025-05-12', 'amount' => -3000, 'original_amount' => -3000]);
        // The in-progress year aggregates as a dimmed, incomplete preview.
        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2026-03-05', 'amount' => 6000, 'original_amount' => 6000]);
        // An August 2026 posting is beyond the last completed month: it lands
        // in the series' current-year point but stays out of the summary.
        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2026-08-05', 'amount' => 999, 'original_amount' => 999]);

        $this->actingAs($user)
            ->get(route('dashboard', ['period' => 'years']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.selected', 'years')
                ->where('period.interval', 'year')
                ->where('period.start', '2024-01-01')
                ->where('period.end', '2026-06-30')
                // Jan 2024 – Jun 2026 is 30 months = 2.5 years of averaging.
                ->where('period.units', 2.5)
                ->where('summary.income', 26000)
                ->where('summary.consumption', 3000)
                ->where('series.0.period', '2024')
                ->where('series.0.complete', true)
                ->where('series.0.income', 8000)
                ->where('series.1.period', '2025')
                ->where('series.1.income', 12000)
                ->where('series.1.consumption', 3000)
                ->where('series.1.result', 9000)
                ->where('series.2.period', '2026')
                ->where('series.2.complete', false)
                ->where('series.2.income', 6999));
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('the years breakdown averages per year and can focus a single year', function () {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00');

    try {
        ['user' => $user, 'account' => $account, 'salary' => $salary, 'groceries' => $groceries] = seedBudget();

        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2024-05-05', 'amount' => 24000, 'original_amount' => 24000]);
        Posting::factory()->forAccount($account)->categorizedAs($groceries)
            ->create(['date' => '2024-06-12', 'amount' => -6000, 'original_amount' => -6000]);
        Posting::factory()->forAccount($account)->categorizedAs($groceries)
            ->create(['date' => '2025-06-12', 'amount' => -12000, 'original_amount' => -12000]);

        // The window ends at the last completed month of data: Jan 2024 –
        // Jun 2025 (latest posting 2025-06) = 18 months = 1.5 units.
        $response = $this->actingAs($user)
            ->get(route('dashboard', ['period' => 'years']), breakdownPartialHeaders())
            ->assertOk();

        $response->assertJsonPath('props.breakdown.focus', null);
        $response->assertJsonPath('props.breakdown.categories.0.name', 'Income');
        $response->assertJsonPath('props.breakdown.categories.0.total', 16000);
        $response->assertJsonPath('props.breakdown.categories.1.name', 'Housing');
        $response->assertJsonPath('props.breakdown.categories.1.total', 12000);

        // Focusing a year flips the breakdown to that year's raw totals.
        $this->actingAs($user)
            ->get(
                route('dashboard', ['period' => 'years', 'focus' => '2025']),
                breakdownPartialHeaders(),
            )
            ->assertOk()
            ->assertJsonPath('props.breakdown.focus', '2025')
            ->assertJsonCount(1, 'props.breakdown.categories')
            ->assertJsonPath('props.breakdown.categories.0.name', 'Housing')
            ->assertJsonPath('props.breakdown.categories.0.total', 12000);

        // A month focus is not selectable on a yearly report.
        $this->actingAs($user)
            ->get(
                route('dashboard', ['period' => 'years', 'focus' => '2025-06']),
                breakdownPartialHeaders(),
            )
            ->assertOk()
            ->assertJsonPath('props.breakdown.focus', null);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('selecting a calendar year windows to that year but never beyond the last completed month', function () {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00');

    try {
        ['user' => $user, 'account' => $account, 'salary' => $salary] = seedBudget();

        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2026-08-05', 'amount' => 999, 'original_amount' => 999]);
        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2026-03-05', 'amount' => 500, 'original_amount' => 500]);

        $this->actingAs($user)
            ->get(route('dashboard', ['period' => '2026']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.selected', '2026')
                ->where('period.start', '2026-01-01')
                ->where('period.end', '2026-06-30')
                ->where('summary.income', 500));
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('invalid period falls back to the default 12 month window', function () {
    ['user' => $user] = seedBudget();

    $this->actingAs($user)
        ->get(route('dashboard', ['period' => 'bogus']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('period.selected', '12m')
            ->where('period.months', 12));
});

test('dashboard uses completed months when latest import is in current partial month', function () {
    CarbonImmutable::setTestNow('2026-06-08 12:00:00');

    try {
        ['user' => $user, 'account' => $account, 'salary' => $salary] = seedBudget();

        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2025-06-30', 'amount' => 100, 'original_amount' => 100]);
        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2026-06-05', 'amount' => 999, 'original_amount' => 999]);

        $this->actingAs($user)
            ->get(route('dashboard', ['period' => '12m']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.start', '2025-06-01')
                ->where('period.end', '2026-05-31')
                ->where('summary.income', 100)
                ->where('series.0.period', '2025-06')
                ->where('series.12.period', '2026-06'));
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('breakdown includes income and expense categories with top subcategories for the period average', function () {
    CarbonImmutable::setTestNow('2026-02-15 12:00:00');

    try {
        ['user' => $user, 'account' => $account, 'salary' => $salary, 'rent' => $rent, 'groceries' => $groceries] = seedBudget();

        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2026-01-05', 'amount' => 10000, 'original_amount' => 10000]);
        Posting::factory()->forAccount($account)->categorizedAs($rent)
            ->create(['date' => '2026-01-10', 'amount' => -3000, 'original_amount' => -3000]);
        Posting::factory()->forAccount($account)->categorizedAs($groceries)
            ->create(['date' => '2026-01-12', 'amount' => -1000, 'original_amount' => -1000]);

        // Deferred prop: request it explicitly via a partial reload (JSON response).
        $response = $this->actingAs($user)
            ->get(route('dashboard', ['months' => 12]), breakdownPartialHeaders())
            ->assertOk();

        // Two categories, sorted by monthly average descending: income (833.33) then Housing (333.33).
        $response->assertJsonPath('props.breakdown.focus', null);
        $response->assertJsonCount(2, 'props.breakdown.categories');
        $response->assertJsonPath('props.breakdown.categories.0.type', 'income');
        $response->assertJsonPath('props.breakdown.categories.0.name', 'Income');
        $response->assertJsonPath('props.breakdown.categories.0.bucket', 'income');
        $response->assertJsonPath('props.breakdown.categories.0.total', 833.33);
        $response->assertJsonPath('props.breakdown.categories.1.type', 'expense');
        $response->assertJsonPath('props.breakdown.categories.1.name', 'Housing');
        // Rent (fixed, 3000) outweighs groceries (variable, 1000), so the
        // category's dominant bucket is bills.
        $response->assertJsonPath('props.breakdown.categories.1.bucket', 'bills');
        $response->assertJsonPath('props.breakdown.categories.1.total', 333.33);
        $response->assertJsonPath('props.breakdown.categories.1.topSubcategories.0.name', 'Rent');
        // Subcategory amounts are averaged over the same divisor as the total: 3000 / 12.
        $response->assertJsonPath('props.breakdown.categories.1.topSubcategories.0.amount', 250);
        $response->assertJsonPath('props.breakdown.categories.1.topSubcategories.1.name', 'Groceries');
        $response->assertJsonPath('props.breakdown.categories.1.topSubcategories.1.amount', 83.33);

        // Largest postings across the category, ordered by absolute amount.
        $response->assertJsonCount(2, 'props.breakdown.categories.1.topPostings');
        $response->assertJsonPath('props.breakdown.categories.1.topPostings.0.amount', 3000);
        $response->assertJsonPath('props.breakdown.categories.1.topPostings.0.date', '2026-01-10');
        $response->assertJsonPath('props.breakdown.categories.1.topPostings.1.amount', 1000);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('breakdown category bucket follows where most of its money lands', function () {
    CarbonImmutable::setTestNow('2026-02-15 12:00:00');

    try {
        ['user' => $user, 'account' => $account, 'rent' => $rent, 'groceries' => $groceries] = seedBudget();

        // Variable spending outweighs the fixed bill within the same category.
        Posting::factory()->forAccount($account)->categorizedAs($rent)
            ->create(['date' => '2026-01-10', 'amount' => -500, 'original_amount' => -500]);
        Posting::factory()->forAccount($account)->categorizedAs($groceries)
            ->create(['date' => '2026-01-12', 'amount' => -1500, 'original_amount' => -1500]);

        $this->actingAs($user)
            ->get(route('dashboard', ['months' => 12]), breakdownPartialHeaders())
            ->assertOk()
            ->assertJsonPath('props.breakdown.categories.0.name', 'Housing')
            ->assertJsonPath('props.breakdown.categories.0.bucket', 'consumption');
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('breakdown month selection returns totals for that month only', function () {
    CarbonImmutable::setTestNow('2026-04-15 12:00:00');

    try {
        ['user' => $user, 'account' => $account, 'salary' => $salary, 'groceries' => $groceries] = seedBudget();

        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2026-02-05', 'amount' => 8000, 'original_amount' => 8000]);
        Posting::factory()->forAccount($account)->categorizedAs($groceries)
            ->create(['date' => '2026-02-12', 'amount' => -2000, 'original_amount' => -2000]);
        Posting::factory()->forAccount($account)->categorizedAs($groceries)
            ->create(['date' => '2026-01-12', 'amount' => -9999, 'original_amount' => -9999]);

        $response = $this->actingAs($user)
            ->get(
                route('dashboard', ['months' => 12, 'focus' => '2026-02']),
                breakdownPartialHeaders(),
            )
            ->assertOk();

        $response->assertJsonPath('props.breakdown.focus', '2026-02');
        $response->assertJsonCount(2, 'props.breakdown.categories');
        $response->assertJsonPath('props.breakdown.categories.0.name', 'Income');
        $response->assertJsonPath('props.breakdown.categories.0.type', 'income');
        $response->assertJsonPath('props.breakdown.categories.0.total', 8000);
        $response->assertJsonPath('props.breakdown.categories.1.name', 'Housing');
        $response->assertJsonPath('props.breakdown.categories.1.total', 2000);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('breakdown month outside the selected period is ignored', function () {
    ['user' => $user, 'account' => $account, 'groceries' => $groceries] = seedBudget();

    Posting::factory()->forAccount($account)->categorizedAs($groceries)
        ->create(['date' => '2026-02-12', 'amount' => -2000, 'original_amount' => -2000]);

    $this->actingAs($user)
        ->get(
            route('dashboard', ['months' => 3, 'focus' => '2000-01']),
            breakdownPartialHeaders(),
        )
        ->assertOk()
        ->assertJsonPath('props.breakdown.focus', null);
});

test('breakdown can target the in-progress preview month', function () {
    CarbonImmutable::setTestNow('2026-04-15 12:00:00');

    try {
        ['user' => $user, 'account' => $account, 'groceries' => $groceries] = seedBudget();

        // Current (incomplete) month plus a completed-month posting that
        // must not leak into the selected-month breakdown.
        Posting::factory()->forAccount($account)->categorizedAs($groceries)
            ->create(['date' => '2026-04-12', 'amount' => -2000, 'original_amount' => -2000]);
        Posting::factory()->forAccount($account)->categorizedAs($groceries)
            ->create(['date' => '2026-03-12', 'amount' => -500, 'original_amount' => -500]);

        $response = $this->actingAs($user)
            ->get(
                route('dashboard', ['focus' => '2026-04']),
                breakdownPartialHeaders(),
            )
            ->assertOk();

        $response->assertJsonPath('props.breakdown.focus', '2026-04');
        $response->assertJsonCount(1, 'props.breakdown.categories');
        $response->assertJsonPath('props.breakdown.categories.0.name', 'Housing');
        $response->assertJsonPath('props.breakdown.categories.0.total', 2000);

        // The period itself still ends at the last completed month, so the
        // preview month stays out of the averages.
        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.end', '2026-03-31')
                ->where('summary.consumption', 500));
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('recategorizing a posting moves it between buckets', function () {
    ['user' => $user, 'account' => $account, 'rent' => $rent, 'groceries' => $groceries] = seedBudget();

    // A fixed bill lands in the bills bucket.
    $posting = Posting::factory()->forAccount($account)->categorizedAs($rent)
        ->create(['date' => '2026-01-10', 'amount' => -900, 'original_amount' => -900]);

    $this->actingAs($user)
        ->get(route('dashboard', ['months' => 3]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.bills', 900)
            ->where('summary.consumption', 0));

    // Recategorizing under a variable subcategory moves it to consumption,
    // without touching any denormalized type snapshot.
    $posting->update(['categorized_subcategory_id' => $groceries->id]);

    $this->actingAs($user)
        ->get(route('dashboard', ['months' => 3]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.bills', 0)
            ->where('summary.consumption', 900));
});

test('savings and investment categories land in their own buckets', function () {
    ['user' => $user, 'account' => $account, 'savings' => $savings, 'investment' => $investment] = seedBudget();

    Posting::factory()->forAccount($account)->categorizedAs($savings)
        ->create(['date' => '2026-01-10', 'amount' => -400, 'original_amount' => -400]);
    Posting::factory()->forAccount($account)->categorizedAs($investment)
        ->create(['date' => '2026-01-11', 'amount' => -250, 'original_amount' => -250]);

    $this->actingAs($user)
        ->get(route('dashboard', ['months' => 3]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.savings', 400)
            ->where('summary.investments', 250)
            ->where('summary.out', 650)
            ->where('summary.result', -650));
});

test('excluded categories are omitted from the overview', function () {
    ['user' => $user, 'account' => $account, 'salary' => $salary, 'transfer' => $transfer] = seedBudget();

    Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
        ->create(['date' => '2026-01-05', 'amount' => 5000, 'original_amount' => 5000]);
    Posting::factory()->forAccount($account)->categorizedAs($transfer)
        ->create(['date' => '2026-01-06', 'amount' => -5000, 'original_amount' => -5000]);
    Posting::factory()->forAccount($account)->categorizedAs($transfer)
        ->create(['date' => '2026-01-07', 'amount' => 5000, 'original_amount' => 5000]);

    $this->actingAs($user)
        ->get(route('dashboard', ['months' => 3]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.income', 5000)
            ->where('summary.out', 0)
            ->where('summary.result', 5000));
});

test('uncategorized postings bucket by sign', function () {
    ['user' => $user, 'account' => $account] = seedBudget();

    // Negative uncategorized spending goes to the uncategorized bucket.
    Posting::factory()->forAccount($account)->uncategorized()
        ->create(['date' => '2026-01-10', 'amount' => -320, 'original_amount' => -320]);
    // Positive uncategorized inflow reads as income.
    Posting::factory()->forAccount($account)->uncategorized()
        ->create(['date' => '2026-01-11', 'amount' => 180, 'original_amount' => 180]);

    $this->actingAs($user)
        ->get(route('dashboard', ['months' => 3]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.uncategorized', 320)
            ->where('summary.income', 180)
            ->where('summary.out', 320)
            ->where('summary.result', -140));
});

test('extraordinary postings are excluded from every bucket', function () {
    ['user' => $user, 'account' => $account, 'groceries' => $groceries] = seedBudget();

    Posting::factory()->forAccount($account)->categorizedAs($groceries)
        ->create(['date' => '2026-01-10', 'amount' => -500, 'original_amount' => -500]);
    Posting::factory()->forAccount($account)->categorizedAs($groceries)
        ->create(['date' => '2026-01-11', 'amount' => -9999, 'original_amount' => -9999, 'is_extraordinary' => true]);

    $this->actingAs($user)
        ->get(route('dashboard', ['months' => 3]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.consumption', 500)
            ->where('summary.out', 500));
});

test('the balance prop sums active accounts in the reporting currency', function () {
    $user = User::factory()->create(['reporting_currency' => 'EUR']);
    BankAccount::factory()->for($user)->create([
        'currency' => 'EUR',
        'is_active' => true,
        'current_balance' => 1000,
    ]);
    // Accounts without a known balance contribute nothing.
    BankAccount::factory()->for($user)->create([
        'currency' => 'EUR',
        'is_active' => true,
        'current_balance' => null,
    ]);
    BankAccount::factory()->for($user)->create([
        'currency' => 'EUR',
        'is_active' => true,
        'current_balance' => 250,
    ]);
    // Inactive accounts are ignored entirely.
    BankAccount::factory()->for($user)->inactive()->create([
        'currency' => 'EUR',
        'current_balance' => 5000,
    ]);
    // A foreign account without a cached rate is counted as missing.
    BankAccount::factory()->for($user)->create([
        'currency' => 'USD',
        'is_active' => true,
        'current_balance' => 7000,
    ]);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('primaryCurrency', 'EUR')
            ->where('balance.total', 1250)
            ->where('balance.currency', 'EUR')
            ->where('balance.missing_accounts', 2));
});
