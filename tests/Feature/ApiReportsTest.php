<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Laravel\Passport\Passport;

test('report endpoints require authentication', function () {
    $this->getJson('/api/reports/overview')->assertUnauthorized();
    $this->getJson('/api/reports/overview/categories')->assertUnauthorized();
});

test('report endpoints require a read token ability', function () {
    Passport::actingAs(User::factory()->create(), ['write']);

    $this->getJson('/api/reports/overview')->assertForbidden();
    $this->getJson('/api/reports/overview/categories')->assertForbidden();
});

test('the overview report aggregates monthly buckets over the requested window', function () {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00');

    try {
        $user = User::factory()->create(['reporting_currency' => 'DKK']);
        $account = BankAccount::factory()->for($user)->create();
        $salary = Subcategory::factory()->forCategory(Category::factory()->income()->create())->create();
        $rent = Subcategory::factory()->forCategory(Category::factory()->create())->create(['expense_group' => 'Bills']);

        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2026-01-05', 'amount' => 10000, 'original_amount' => 10000]);
        Posting::factory()->forAccount($account)->categorizedAs($rent)
            ->create(['date' => '2026-01-10', 'amount' => -1500, 'original_amount' => -1500]);
        // Outside the requested window.
        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2024-01-05', 'amount' => 99999, 'original_amount' => 99999]);
        // Another user's postings never leak in.
        Posting::factory()->create(['date' => '2026-01-06', 'amount' => 7777, 'original_amount' => 7777]);

        Passport::actingAs($user, ['read']);

        $this->getJson('/api/reports/overview?interval=month&from=2026-01&to=2026-03')
            ->assertOk()
            ->assertJsonPath('data.interval', 'month')
            ->assertJsonPath('data.from', '2026-01-01')
            ->assertJsonPath('data.to', '2026-03-31')
            ->assertJsonPath('data.currency', 'DKK')
            ->assertJsonPath('data.summary.income', 10000)
            ->assertJsonPath('data.summary.bills', 1500)
            ->assertJsonPath('data.summary.out', 1500)
            ->assertJsonPath('data.summary.result', 8500)
            ->assertJsonCount(3, 'data.series')
            ->assertJsonPath('data.series.0.period', '2026-01')
            ->assertJsonPath('data.series.0.complete', true)
            ->assertJsonPath('data.series.0.income', 10000)
            ->assertJsonPath('data.series.1.period', '2026-02')
            ->assertJsonPath('data.series.1.result', 0);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('the overview report aggregates yearly when interval=year', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $salary = Subcategory::factory()->forCategory(Category::factory()->income()->create())->create();

    Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
        ->create(['date' => '2024-06-05', 'amount' => 8000, 'original_amount' => 8000]);
    Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
        ->create(['date' => '2025-06-05', 'amount' => 12000, 'original_amount' => 12000]);

    Passport::actingAs($user, ['read']);

    $this->getJson('/api/reports/overview?interval=year&from=2024&to=2025')
        ->assertOk()
        ->assertJsonPath('data.interval', 'year')
        ->assertJsonPath('data.from', '2024-01-01')
        ->assertJsonPath('data.to', '2025-12-31')
        ->assertJsonPath('data.summary.income', 20000)
        ->assertJsonCount(2, 'data.series')
        ->assertJsonPath('data.series.0.period', '2024')
        ->assertJsonPath('data.series.0.income', 8000)
        ->assertJsonPath('data.series.1.period', '2025')
        ->assertJsonPath('data.series.1.income', 12000);
});

test('the categories report totals the window per category and can split by period', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $income = Category::factory()->income()->create(['name' => 'Income']);
    $salary = Subcategory::factory()->forCategory($income)->create(['name' => 'Salary']);
    $housing = Category::factory()->create(['name' => 'Housing']);
    $rent = Subcategory::factory()->forCategory($housing)->create(['name' => 'Rent', 'expense_group' => 'Bills']);
    $groceries = Subcategory::factory()->forCategory($housing)->create(['name' => 'Groceries', 'expense_group' => 'LivingCosts']);

    Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
        ->create(['date' => '2025-01-05', 'amount' => 10000, 'original_amount' => 10000]);
    Posting::factory()->forAccount($account)->categorizedAs($rent)
        ->create(['date' => '2025-01-10', 'amount' => -1500, 'original_amount' => -1500]);
    Posting::factory()->forAccount($account)->categorizedAs($groceries)
        ->create(['date' => '2026-02-12', 'amount' => -400, 'original_amount' => -400]);

    Passport::actingAs($user, ['read']);

    // Window totals: raw sums per category with subcategory rollups.
    $this->getJson('/api/reports/overview/categories?from=2025&to=2026')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Income')
        ->assertJsonPath('data.0.type', 'income')
        ->assertJsonPath('data.0.total', 10000)
        ->assertJsonPath('data.1.name', 'Housing')
        ->assertJsonPath('data.1.type', 'expense')
        ->assertJsonPath('data.1.bucket', 'bills')
        ->assertJsonPath('data.1.total', 1900)
        ->assertJsonCount(2, 'data.1.subcategories')
        ->assertJsonPath('data.1.top_postings.0.amount', 1500)
        ->assertJsonMissingPath('data.0.series');

    // With an interval each category additionally reports per-period totals.
    $this->getJson('/api/reports/overview/categories?from=2025&to=2026&interval=year')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Income')
        ->assertJsonCount(1, 'data.0.series')
        ->assertJsonPath('data.0.series.0.period', '2025')
        ->assertJsonPath('data.0.series.0.total', 10000)
        ->assertJsonPath('data.1.series.0.period', '2025')
        ->assertJsonPath('data.1.series.0.total', 1500)
        ->assertJsonPath('data.1.series.1.period', '2026')
        ->assertJsonPath('data.1.series.1.total', 400);
});

test('uncategorized income and expense each report their own period series', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();

    // No subcategory: a positive amount buckets as income, a negative one
    // as expense — both land on category id null.
    Posting::factory()->forAccount($account)->uncategorized()
        ->create(['date' => '2025-01-05', 'amount' => 100, 'original_amount' => 100]);
    Posting::factory()->forAccount($account)->uncategorized()
        ->create(['date' => '2025-01-10', 'amount' => -40, 'original_amount' => -40]);

    Passport::actingAs($user, ['read']);

    $this->getJson('/api/reports/overview/categories?from=2025&to=2025&interval=month')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.type', 'income')
        ->assertJsonPath('data.0.total', 100)
        ->assertJsonPath('data.0.series.0.total', 100)
        ->assertJsonPath('data.1.type', 'expense')
        ->assertJsonPath('data.1.total', 40)
        ->assertJsonPath('data.1.series.0.total', 40);
});

test('report windows and intervals are validated', function () {
    Passport::actingAs(User::factory()->create(), ['read']);

    $this->getJson('/api/reports/overview?interval=bogus')->assertUnprocessable();
    $this->getJson('/api/reports/overview?from=garbage')->assertUnprocessable();
    $this->getJson('/api/reports/overview?from=2026-13')->assertUnprocessable();
    $this->getJson('/api/reports/overview?from=2026-05&to=2026-01')->assertUnprocessable();
    $this->getJson('/api/reports/overview/categories?interval=bogus')->assertUnprocessable();
});

test('report windows default to the full data range', function () {
    CarbonImmutable::setTestNow('2026-07-15 12:00:00');

    try {
        $user = User::factory()->create();
        $account = BankAccount::factory()->for($user)->create();
        $salary = Subcategory::factory()->forCategory(Category::factory()->income()->create())->create();

        Posting::factory()->forAccount($account)->categorizedAs($salary)->income()
            ->create(['date' => '2024-06-05', 'amount' => 8000, 'original_amount' => 8000]);

        Passport::actingAs($user, ['read']);

        $this->getJson('/api/reports/overview?interval=year')
            ->assertOk()
            ->assertJsonPath('data.from', '2024-01-01')
            ->assertJsonPath('data.to', '2026-07-15')
            ->assertJsonCount(3, 'data.series');
    } finally {
        CarbonImmutable::setTestNow();
    }
});
