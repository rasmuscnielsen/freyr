<?php

use App\Enums\CategoryType;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('postings can be filtered with spatie query filter parameters', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create(['name' => 'Main']);
    $otherAccount = BankAccount::factory()->for($user)->create(['name' => 'Savings']);
    $category = Category::factory()->create(['name' => 'Housing']);
    $subcategory = Subcategory::factory()->forCategory($category)->create(['name' => 'Rent']);

    Posting::factory()
        ->forAccount($account)
        ->categorizedAs($subcategory)
        ->create([
            'description' => 'Monthly rent',
            'date' => '2026-01-02',
            'amount' => -8000,
        ]);

    Posting::factory()
        ->forAccount($otherAccount)
        ->categorizedAs($subcategory)
        ->create([
            'description' => 'Groceries',
            'date' => '2026-01-03',
            'amount' => -300,
        ]);

    $this->actingAs($user)
        ->get(route('postings.index', [
            'filter' => [
                'account' => $account->id,
                'text' => 'rent',
            ],
        ]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('postings/index')
            ->has('postings.data', 1)
            ->where('postings.data.0.description', 'Monthly rent'));
});

test('posting categories can be updated by their owner', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $category = Category::factory()->create();
    $subcategory = Subcategory::factory()->forCategory($category)->create();
    $posting = Posting::factory()->forAccount($account)->uncategorized()->create();

    $this->actingAs($user)
        ->patch(route('postings.update', $posting), [
            'categorized_subcategory_id' => $subcategory->id,
        ])
        ->assertRedirect();

    expect($posting->refresh()->categorized_subcategory_id)->toBe($subcategory->id);
});

test('any posting can be marked extraordinary and commented by its owner', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $connection = BankConnection::factory()->for($user)->create();
    BankConnectionAccount::factory()->for($connection)->linkedTo($account)->create();
    $posting = Posting::factory()->forAccount($account)->create([
        'date' => '2026-06-01',
        'amount' => '-100.00',
        'description' => 'Synced posting',
    ]);

    $this->actingAs($user)
        ->patch(route('postings.update', $posting), [
            'categorized_subcategory_id' => null,
            'comment' => 'One-off purchase',
            'is_extraordinary' => true,
        ])
        ->assertRedirect();

    $posting->refresh();

    expect($posting->is_extraordinary)->toBeTrue()
        ->and($posting->comment)->toBe('One-off purchase')
        // The bank-owned fields are untouched by the categorization endpoint.
        ->and($posting->description)->toBe('Synced posting')
        ->and((float) $posting->amount)->toBe(-100.00);
});

test('the postings page lists categories in display order', function () {
    $user = User::factory()->create();
    $exclude = Category::factory()->create(['name' => 'Aaa Exclude', 'type' => CategoryType::Exclude]);
    $income = Category::factory()->create(['name' => 'Zzz Income', 'type' => CategoryType::Income]);
    $expense = Category::factory()->create(['name' => 'Groceries', 'type' => CategoryType::Expense]);
    Subcategory::factory()->forCategory($exclude)->create();
    Subcategory::factory()->forCategory($income)->create();
    Subcategory::factory()->forCategory($expense)->create();

    $this->actingAs($user)
        ->get(route('postings.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('categories.0.name', 'Zzz Income')
            ->where('categories.1.name', 'Groceries')
            ->where('categories.2.name', 'Aaa Exclude'));
});
