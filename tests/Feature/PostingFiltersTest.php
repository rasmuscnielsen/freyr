<?php

use App\Models\BankAccount;
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
