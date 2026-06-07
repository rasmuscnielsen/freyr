<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

test('dashboard aggregates income expenses and category spending', function () {
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

    Posting::factory()
        ->forAccount($account)
        ->categorizedAs($salary)
        ->income()
        ->create([
            'date' => '2026-01-05',
            'amount' => 10000,
        ]);

    Posting::factory()
        ->forAccount($account)
        ->categorizedAs($rent)
        ->create([
            'date' => '2026-01-10',
            'amount' => -1500,
        ]);

    Posting::factory()
        ->forAccount($account)
        ->categorizedAs($groceries)
        ->create([
            'date' => '2026-01-12',
            'amount' => -1000,
        ]);

    Posting::factory()
        ->forAccount($account)
        ->categorizedAs($salary)
        ->income()
        ->create([
            'date' => '2026-01-13',
            'amount' => 500000,
            'category_type_code' => Posting::CATEGORY_TYPE_TRANSFER,
        ]);

    Posting::factory()
        ->forAccount($account)
        ->categorizedAs($savings)
        ->create([
            'date' => '2026-01-14',
            'amount' => -3000,
            'category_type_code' => Posting::CATEGORY_TYPE_SAVING,
        ]);

    Posting::factory()
        ->forAccount($account)
        ->categorizedAs($salary)
        ->income()
        ->create([
            'date' => '2026-01-15',
            'amount' => 20000,
            'is_extraordinary' => true,
        ]);

    $this->actingAs($user)
        ->get(route('dashboard', ['months' => 3]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('dashboard')
            ->where('summary.income', 10000)
            ->where('summary.bills', -1500)
            ->where('summary.consumption', -1000)
            ->where('summary.expenses', 2500)
            ->where('summary.savings', 3000)
            ->where('summary.result', 7500)
            ->where('summary.result_with_savings', 4500)
            ->where('spendingCategories.0.name', 'Housing')
            ->where('spendingCategories.0.amount', 833.33)
            ->where('spendingCategories.0.total_amount', 2500));
});

test('dashboard uses completed months when latest import is in current partial month', function () {
    CarbonImmutable::setTestNow('2026-06-08 12:00:00');

    try {
        $user = User::factory()->create();
        $account = BankAccount::factory()->for($user)->create();
        $incomeCategory = Category::factory()->income()->create(['name' => 'Income']);
        $salary = Subcategory::factory()->forCategory($incomeCategory)->create(['name' => 'Salary']);

        Posting::factory()
            ->forAccount($account)
            ->categorizedAs($salary)
            ->income()
            ->create([
                'date' => '2025-06-30',
                'amount' => 100,
            ]);

        Posting::factory()
            ->forAccount($account)
            ->categorizedAs($salary)
            ->income()
            ->create([
                'date' => '2026-06-05',
                'amount' => 999,
            ]);

        $this->actingAs($user)
            ->get(route('dashboard', ['months' => 12]))
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
