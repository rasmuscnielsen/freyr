<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use Laravel\Passport\Passport;

test('bank accounts can be read by their owner', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create([
        'name' => 'Budget account',
    ]);
    BankAccount::factory()->create(['name' => 'Other account']);

    Passport::actingAs($user, ['read']);

    $this->getJson('/api/bank-accounts')
        ->assertOk()
        ->assertJsonPath('data.0.id', $account->id)
        ->assertJsonCount(1, 'data');

    $this->getJson("/api/bank-accounts/{$account->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Budget account');
});

test('bank accounts cannot be read across users', function () {
    Passport::actingAs(User::factory()->create(), ['read']);

    $account = BankAccount::factory()->create();

    $this->getJson("/api/bank-accounts/{$account->id}")->assertNotFound();
});

test('postings can be read by their owner', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create([
        'name' => 'Checking',
    ]);
    $category = Category::factory()->create(['name' => 'Housing']);
    $subcategory = Subcategory::factory()->forCategory($category)->create([
        'name' => 'Rent',
    ]);
    $posting = Posting::factory()
        ->forAccount($account)
        ->categorizedAs($subcategory)
        ->create([
            'description' => 'Monthly rent',
            'amount' => -12000,
        ]);
    Posting::factory()->create(['description' => 'Other posting']);

    Passport::actingAs($user, ['read']);

    $this->getJson('/api/postings')
        ->assertOk()
        ->assertJsonPath('data.0.id', $posting->id)
        ->assertJsonPath('data.0.bank_account.name', 'Checking')
        ->assertJsonPath('data.0.category.name', 'Housing')
        ->assertJsonCount(1, 'data');

    $this->getJson("/api/postings/{$posting->id}")
        ->assertOk()
        ->assertJsonPath('data.description', 'Monthly rent');
});

test('postings cannot be read across users', function () {
    Passport::actingAs(User::factory()->create(), ['read']);

    $posting = Posting::factory()->create();

    $this->getJson("/api/postings/{$posting->id}")->assertNotFound();
});
