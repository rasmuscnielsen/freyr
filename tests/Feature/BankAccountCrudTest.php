<?php

use App\Finance\EnableBanking\EnableBankingClient;
use App\Finance\Fx\ExchangeRateStore;
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
        'name' => 'Sample Account',
        'bank_key' => 'DK_SampleBank',
        'bank_name' => 'SampleBank',
        'currency' => 'DKK',
        'account_type' => 'Consumption',
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
            ->where('availableBanks.1.name', 'Example Bank'));

    $this->actingAs($user)
        ->post(route('bank-accounts.store'), bankAccountPayload())
        ->assertRedirect();

    $account = BankAccount::query()->firstOrFail();

    expect($account->name)->toBe('Sample Account')
        ->and($account->legacy_id)->toStartWith('manual-');

    $this->actingAs($user)
        ->patch(route('bank-accounts.update', $account), bankAccountPayload([
            'name' => 'Sample Everyday',
            'is_favorite' => true,
        ]))
        ->assertRedirect();

    expect($account->refresh()->name)->toBe('Sample Everyday')
        ->and($account->is_favorite)->toBeTrue();

    $this->actingAs($user)
        ->delete(route('bank-accounts.destroy', $account))
        ->assertRedirect();

    expect(BankAccount::query()->count())->toBe(0);
});

test('creating a manual account only needs a name and a currency', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('bank-accounts.store'), [
            'name' => 'Cash jar',
            'currency' => 'EUR',
        ])
        ->assertRedirect();

    $account = BankAccount::query()->sole();

    expect($account->name)->toBe('Cash jar')
        ->and($account->currency)->toBe('EUR')
        ->and($account->is_active)->toBeTrue()
        ->and($account->is_favorite)->toBeFalse()
        ->and($account->isSpiirImported())->toBeFalse();

    Cache::forget('enable-banking.aspsps.dk.personal.ais');
    app()->instance(EnableBankingClient::class, new BankAccountCrudEnableBankingClient);

    $this->actingAs($user)
        ->get(route('bank-accounts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.0.is_spiir_imported', false));
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

test('bank accounts carry a balance valued in the reporting currency', function () {
    $user = User::factory()->create(['reporting_currency' => 'DKK']);
    Cache::forget('enable-banking.aspsps.dk.personal.ais');
    app()->instance(EnableBankingClient::class, new BankAccountCrudEnableBankingClient);

    // Identical bank names push ordering onto the account name.
    BankAccount::factory()->for($user)->create([
        'name' => 'A Dollars',
        'bank_name' => 'Bank',
        'currency' => 'USD',
        'current_balance' => '50.00',
    ]);
    BankAccount::factory()->for($user)->create([
        'name' => 'B Euros',
        'bank_name' => 'Bank',
        'currency' => 'EUR',
        'current_balance' => '100.00',
    ]);
    BankAccount::factory()->for($user)->create([
        'name' => 'C Kroner',
        'bank_name' => 'Bank',
        'currency' => 'DKK',
        'current_balance' => '1000.00',
    ]);
    BankAccount::factory()->for($user)->create([
        'name' => 'D Unsynced',
        'bank_name' => 'Bank',
        'currency' => 'DKK',
        'current_balance' => null,
    ]);

    app(ExchangeRateStore::class)->storeRates([[
        'date' => now()->toDateString(),
        'base' => 'EUR',
        'quote' => 'DKK',
        'rate' => '7.5000',
    ]]);

    $this->actingAs($user)
        ->get(route('bank-accounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // No cached USD rate — unvalued rather than guessed.
            ->where('accounts.0.reporting_balance', null)
            ->where('accounts.1.reporting_balance', 750)
            // Same-currency balances pass through unconverted.
            ->where('accounts.2.reporting_balance', 1000)
            ->where('accounts.3.reporting_balance', null));
});

test('deleting an account removes it and its postings and unlinks remote accounts', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $posting = Posting::factory()->forAccount($account)->create();
    $connection = BankConnection::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo($account)
        ->create();

    $this->actingAs($user)
        ->delete(route('bank-accounts.destroy', $account))
        ->assertRedirect();

    // The remote account returns to the connection's setup list so it can be
    // linked or created again.
    expect(BankAccount::query()->count())->toBe(0)
        ->and(Posting::query()->whereKey($posting->id)->exists())->toBeFalse()
        ->and($remoteAccount->refresh()->bank_account_id)->toBeNull()
        ->and($remoteAccount->status)->toBe(BankConnectionAccount::STATUS_UNLINKED);
});

test('a newer matching run on another account does not mask a standing balance mismatch', function () {
    $user = User::factory()->create();
    Cache::forget('enable-banking.aspsps.dk.personal.ais');
    app()->instance(EnableBankingClient::class, new BankAccountCrudEnableBankingClient);

    $connection = BankConnection::factory()->for($user)->create();
    $dkkAccount = BankConnectionAccount::factory()->for($connection)->create(['currency' => 'DKK']);
    $eurAccount = BankConnectionAccount::factory()->for($connection)->create(['currency' => 'EUR']);

    // The DKK account's latest run mismatched; the EUR account then synced
    // successfully. The card must still surface the DKK mismatch.
    BankSyncRun::factory()->forConnectionAccount($dkkAccount)->create([
        'expected_balance' => '100.00',
        'computed_balance' => '150.00',
        'balance_matches' => false,
        'balance_match_basis' => 'bank',
    ]);
    BankSyncRun::factory()->forConnectionAccount($eurAccount)->create([
        'expected_balance' => '200.00',
        'computed_balance' => '200.00',
        'balance_matches' => true,
        'balance_match_basis' => 'bank',
    ]);

    $this->actingAs($user)
        ->get(route('bank-accounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.balance_mismatch.delta', '-50.00')
            ->where('connections.0.balance_mismatch.currency', 'DKK'));
});

test('bank accounts page exposes the my banks connection panel', function () {
    $user = User::factory()->create();
    Cache::forget('enable-banking.aspsps.dk.personal.ais');
    app()->instance(EnableBankingClient::class, new BankAccountCrudEnableBankingClient);

    $account = BankAccount::factory()->for($user)->create();
    $connection = BankConnection::factory()->for($user)->forBank('Demo Bank')->create();
    BankConnectionAccount::factory()->for($connection)->linkedTo($account)->create(['details' => 'Everyday']);

    $this->actingAs($user)
        ->get(route('bank-accounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bank-accounts/index')
            ->where('connections.0.aspsp_name', 'Demo Bank')
            ->where('connections.0.linked_accounts_count', 1)
            ->where('connections.0.accounts.0.name', 'Everyday')
            ->where('connections.0.accounts.0.bank_account.name', $account->name)
            ->where('connections.0.accounts.0.bank_account.is_active', true));
});

test('bank pages never expose previously stored exception messages', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $connection = BankConnection::factory()->for($user)->create([
        'status' => BankConnection::STATUS_FAILED,
        'failure_code' => '23503',
        'failure_message' => 'SQLSTATE[23503] secret-transfer-data',
    ]);
    $remote = BankConnectionAccount::factory()->for($connection)->linkedTo($account)->create();
    BankSyncRun::factory()->forConnectionAccount($remote)->failed()->create([
        'error_code' => '23503',
        'error_message' => 'SQLSTATE[23503] secret-transfer-data',
    ]);
    Cache::forget('enable-banking.aspsps.dk.personal.ais');
    app()->instance(EnableBankingClient::class, new BankAccountCrudEnableBankingClient);

    $this->actingAs($user)
        ->get(route('bank-accounts.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('connections.0.failure_message', 'Bank sync failed. Please try again.'))
        ->assertDontSee('secret-transfer-data');

    $this->actingAs($user)
        ->get(route('bank-accounts.show', $account))
        ->assertInertia(fn (Assert $page) => $page
            ->where('syncRuns.0.error_message', 'Bank sync failed. Please try again.'))
        ->assertDontSee('secret-transfer-data');
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
