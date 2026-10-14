<?php

use App\Models\BankAccount;
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
