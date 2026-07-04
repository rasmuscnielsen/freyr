<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Build a small budget with income, a fixed bill, a variable expense and a saving.
 *
 * @return array{user: User, account: BankAccount, salary: Subcategory, rent: Subcategory, groceries: Subcategory, savings: Subcategory}
 */
function seedBudget(): array
{
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $incomeCategory = Category::factory()->income()->create(['name' => 'Income']);
    $expenseCategory = Category::factory()->create(['name' => 'Housing']);
    $savingsCategory = Category::factory()->create(['name' => 'Savings', 'type' => 'Saving']);
    $salary = Subcategory::factory()->forCategory($incomeCategory)->create(['name' => 'Salary']);
    $rent = Subcategory::factory()->forCategory($expenseCategory)->create([
        'name' => 'Rent',
        'expense_type' => 'Fixed',
    ]);
    $groceries = Subcategory::factory()->forCategory($expenseCategory)->create([
        'name' => 'Groceries',
        'expense_type' => 'Variable',
    ]);
    $savings = Subcategory::factory()->forCategory($savingsCategory)->create(['name' => 'Pension']);

    return compact('user', 'account', 'salary', 'rent', 'groceries', 'savings');
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
    ['user' => $user, 'account' => $account, 'salary' => $salary, 'rent' => $rent, 'groceries' => $groceries, 'savings' => $savings] = seedBudget();

    Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
        ->create(['date' => '2026-01-05', 'amount' => 10000]);
    Posting::factory()->forAccount($account)->categorizedAs($rent)
        ->create(['date' => '2026-01-10', 'amount' => -1500]);
    Posting::factory()->forAccount($account)->categorizedAs($groceries)
        ->create(['date' => '2026-01-12', 'amount' => -1000]);
    Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
        ->create(['date' => '2026-01-13', 'amount' => 500000, 'category_type_code' => Posting::CATEGORY_TYPE_TRANSFER]);
    Posting::factory()->forAccount($account)->categorizedAs($savings)
        ->create(['date' => '2026-01-14', 'amount' => -3000, 'category_type_code' => Posting::CATEGORY_TYPE_SAVING]);
    Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
        ->create(['date' => '2026-01-15', 'amount' => 20000, 'is_extraordinary' => true]);

    $this->actingAs($user)
        ->get(route('dashboard', ['months' => 3]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('period.selected', '12m')
            ->where('period.months', 3)
            ->where('summary.income', 10000)
            ->where('summary.bills', -1500)
            ->where('summary.consumption', -1000)
            ->where('summary.expenses', 2500)
            ->where('summary.savings', 3000)
            ->where('summary.result', 7500)
            ->where('summary.result_with_savings', 4500));
});

test('period options include 12m and the years from earliest posting to now', function () {
    CarbonImmutable::setTestNow('2026-07-01 12:00:00');

    try {
        ['user' => $user, 'account' => $account, 'salary' => $salary] = seedBudget();

        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2024-03-05', 'amount' => 100]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.selected', '12m')
                ->where('period.options', ['12m', '2026', '2025', '2024']));
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('selecting a calendar year windows to that year but never beyond the last completed month', function () {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00');

    try {
        ['user' => $user, 'account' => $account, 'salary' => $salary] = seedBudget();

        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2026-08-05', 'amount' => 999]);
        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2026-03-05', 'amount' => 500]);

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
            ->create(['date' => '2025-06-30', 'amount' => 100]);
        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2026-06-05', 'amount' => 999]);

        $this->actingAs($user)
            ->get(route('dashboard', ['period' => '12m']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('period.start', '2025-06-01')
                ->where('period.end', '2026-05-31')
                ->where('summary.income', 100)
                ->where('monthly.0.month', '2025-06')
                ->where('monthly.12.month', '2026-06'));
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('breakdown includes income and expense categories with top subcategories for the period average', function () {
    CarbonImmutable::setTestNow('2026-02-15 12:00:00');

    try {
        ['user' => $user, 'account' => $account, 'salary' => $salary, 'rent' => $rent, 'groceries' => $groceries] = seedBudget();

        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2026-01-05', 'amount' => 10000]);
        Posting::factory()->forAccount($account)->categorizedAs($rent)
            ->create(['date' => '2026-01-10', 'amount' => -3000]);
        Posting::factory()->forAccount($account)->categorizedAs($groceries)
            ->create(['date' => '2026-01-12', 'amount' => -1000]);

        // Deferred prop: request it explicitly via a partial reload (JSON response).
        $response = $this->actingAs($user)
            ->get(route('dashboard', ['months' => 12]), breakdownPartialHeaders())
            ->assertOk();

        // Two categories, sorted by monthly average descending: income (833.33) then Housing (333.33).
        $response->assertJsonPath('props.breakdown.month', null);
        $response->assertJsonCount(2, 'props.breakdown.categories');
        $response->assertJsonPath('props.breakdown.categories.0.type', 'income');
        $response->assertJsonPath('props.breakdown.categories.0.name', 'Income');
        $response->assertJsonPath('props.breakdown.categories.0.total', 833.33);
        $response->assertJsonPath('props.breakdown.categories.1.type', 'expense');
        $response->assertJsonPath('props.breakdown.categories.1.name', 'Housing');
        $response->assertJsonPath('props.breakdown.categories.1.total', 333.33);
        $response->assertJsonPath('props.breakdown.categories.1.topSubcategories.0.name', 'Rent');
        $response->assertJsonPath('props.breakdown.categories.1.topSubcategories.0.amount', 3000);

        // Largest postings across the category, ordered by absolute amount.
        $response->assertJsonCount(2, 'props.breakdown.categories.1.topPostings');
        $response->assertJsonPath('props.breakdown.categories.1.topPostings.0.amount', 3000);
        $response->assertJsonPath('props.breakdown.categories.1.topPostings.0.date', '2026-01-10');
        $response->assertJsonPath('props.breakdown.categories.1.topPostings.1.amount', 1000);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('breakdown month selection returns totals for that month only', function () {
    CarbonImmutable::setTestNow('2026-04-15 12:00:00');

    try {
        ['user' => $user, 'account' => $account, 'salary' => $salary, 'groceries' => $groceries] = seedBudget();

        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2026-02-05', 'amount' => 8000]);
        Posting::factory()->forAccount($account)->categorizedAs($groceries)
            ->create(['date' => '2026-02-12', 'amount' => -2000]);
        Posting::factory()->forAccount($account)->categorizedAs($groceries)
            ->create(['date' => '2026-01-12', 'amount' => -9999]);

        $response = $this->actingAs($user)
            ->get(
                route('dashboard', ['months' => 12, 'breakdownMonth' => '2026-02']),
                breakdownPartialHeaders(),
            )
            ->assertOk();

        $response->assertJsonPath('props.breakdown.month', '2026-02');
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
        ->create(['date' => '2026-02-12', 'amount' => -2000]);

    $this->actingAs($user)
        ->get(
            route('dashboard', ['months' => 3, 'breakdownMonth' => '2000-01']),
            breakdownPartialHeaders(),
        )
        ->assertOk()
        ->assertJsonPath('props.breakdown.month', null);
});
