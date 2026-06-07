<?php

use App\Finance\EnableBanking\EnableBankingClient;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\Posting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

function bankAccountPayload(array $overrides = []): array
{
    return [
        'name' => 'Wise DKK',
        'bank_key' => 'DK_Wise',
        'bank_name' => 'Wise',
        'currency' => 'DKK',
        'last_booked_balance' => '1200.50',
        'last_available_balance' => '1200.50',
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
        ->create(['details' => 'Main account']);
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

class BankAccountCrudEnableBankingClient extends EnableBankingClient
{
    /**
     * @return array<string, mixed>
     */
    public function aspsps(?string $country = null, ?string $psuType = null, ?string $service = 'AIS'): array
    {
        return [
            'aspsps' => [
                ['name' => 'Nykredit Bank', 'country' => 'DK'],
                ['name' => 'Lunar Bank', 'country' => 'DK'],
            ],
        ];
    }
}
