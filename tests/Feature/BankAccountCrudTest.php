<?php

use App\Finance\EnableBanking\EnableBankingClient;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\BankSyncRun;
use App\Models\Posting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\BankAccountCrudEnableBankingClient;

function bankAccountPayload(array $overrides = []): array
{
    return [
        'name' => 'Wise DKK',
        'bank_key' => 'DK_Wise',
        'bank_name' => 'Wise',
        'currency' => 'DKK',
        'account_type' => 'Consumption',
        'account_subcategory_key' => 'consumption',
        'last_known_posting_date' => '2026-06-05',
        'is_active' => true,
        'is_favorite' => false,
        ...$overrides,
    ];
}

test('bank accounts can be listed created updated and deleted', function () {
    $user = User::factory()->create();
    Cache::forget('enable-banking.aspsps.dk.personal.ais');
    app()->instance(EnableBankingClient::class, new BankAccountCrudEnableBankingClient);

    $this->actingAs($user)
        ->get(route('bank-accounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bank-accounts/index')
            ->has('accounts', 0)
            ->where('availableBanks.1.name', 'Nykredit Bank'));

    $this->actingAs($user)
        ->post(route('bank-accounts.store'), bankAccountPayload())
        ->assertRedirect();

    $account = BankAccount::query()->firstOrFail();

    expect($account->name)->toBe('Wise DKK')
        ->and($account->legacy_id)->toStartWith('manual-');

    $this->actingAs($user)
        ->patch(route('bank-accounts.update', $account), bankAccountPayload([
            'name' => 'Wise Everyday',
            'is_favorite' => true,
        ]))
        ->assertRedirect();

    expect($account->refresh()->name)->toBe('Wise Everyday')
        ->and($account->is_favorite)->toBeTrue();

    $this->actingAs($user)
        ->delete(route('bank-accounts.destroy', $account))
        ->assertRedirect();

    expect(BankAccount::query()->count())->toBe(0);
});

test('bank accounts page exposes connection state and linkable remote accounts', function () {
    $user = User::factory()->create();
    Cache::forget('enable-banking.aspsps.dk.personal.ais');
    app()->instance(EnableBankingClient::class, new BankAccountCrudEnableBankingClient);

    $account = BankAccount::factory()->for($user)->create(['name' => 'Everyday']);
    $connection = BankConnection::factory()->for($user)->create();
    BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo($account)
        ->create([
            'details' => 'Main account',
            'last_synced_at' => '2026-06-20 10:30:00',
        ]);
    BankConnectionAccount::factory()
        ->for($connection)
        ->create(['user_id' => $user->id, 'details' => 'Savings']);

    $this->actingAs($user)
        ->get(route('bank-accounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bank-accounts/index')
            ->where('accounts.0.name', 'Everyday')
            ->where('accounts.0.connection_accounts.0.name', 'Main account')
            ->where('accounts.0.connection_accounts.0.last_synced_at', '2026-06-20 10:30:00')
            ->where('linkableConnectionAccounts.0.name', 'Savings'));
});

test('bank accounts with postings are archived instead of deleted', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    Posting::factory()->forAccount($account)->create();

    $this->actingAs($user)
        ->delete(route('bank-accounts.destroy', $account))
        ->assertRedirect();

    expect($account->refresh()->is_active)->toBeFalse()
        ->and(BankAccount::query()->count())->toBe(1);
});

test('bank accounts page exposes the my banks connection panel', function () {
    $user = User::factory()->create();
    Cache::forget('enable-banking.aspsps.dk.personal.ais');
    app()->instance(EnableBankingClient::class, new BankAccountCrudEnableBankingClient);

    $account = BankAccount::factory()->for($user)->create();
    $connection = BankConnection::factory()->for($user)->forBank('Lunar Bank')->create();
    BankConnectionAccount::factory()->for($connection)->linkedTo($account)->create(['details' => 'Everyday']);

    $this->actingAs($user)
        ->get(route('bank-accounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bank-accounts/index')
            ->where('connections.0.aspsp_name', 'Lunar Bank')
            ->where('connections.0.linked_accounts_count', 1)
            ->where('connections.0.accounts.0.name', 'Everyday')
            ->where('connections.0.accounts.0.bank_account.name', $account->name));
});

test('bank account detail page renders postings and sync history', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create(['name' => 'Everyday']);
    $connection = BankConnection::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($account)->create();
    Posting::factory()->forAccount($account)->create(['description' => 'Grocery run', 'date' => '2026-06-15']);
    BankSyncRun::factory()->forConnectionAccount($remoteAccount->refresh())->create();

    $this->actingAs($user)
        ->get(route('bank-accounts.show', $account))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bank-accounts/show')
            ->where('account.name', 'Everyday')
            ->where('account.connection.aspsp_name', $connection->aspsp_name)
            ->has('postings.data', 1)
            ->where('postings.data.0.description', 'Grocery run')
            ->has('syncRuns', 1));
});

test('bank account detail surfaces a live balance diff against the postings chain', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create(['current_balance' => '150.00']);
    $connection = BankConnection::factory()->for($user)->create();
    BankConnectionAccount::factory()->for($connection)->linkedTo($account)->create();
    Posting::factory()->forAccount($account)->create(['balance' => '100.00']);

    $this->actingAs($user)
        ->get(route('bank-accounts.show', $account))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('account.balance_diff', '50.00'));
});

test('bank account detail page is authorized to the owner', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $account = BankAccount::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->get(route('bank-accounts.show', $account))
        ->assertForbidden();
});
