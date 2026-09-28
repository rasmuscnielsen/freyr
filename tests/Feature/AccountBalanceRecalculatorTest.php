<?php

use App\Finance\AccountBalanceRecalculator;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\Posting;
use App\Models\User;

function recalculateManualAccount(BankAccount $account): void
{
    app(AccountBalanceRecalculator::class)->recalculate($account);
}

function manualBalanceAccount(array $attributes = []): BankAccount
{
    return BankAccount::factory()
        ->for(User::factory()->create())
        ->create(['current_balance' => null, 'opening_balance' => '0.00', ...$attributes]);
}

test('manual account total uses its opening balance and original currency movements without writing posting balances', function () {
    $account = manualBalanceAccount(['opening_balance' => '1000.00']);
    $first = Posting::factory()->forAccount($account)->create([
        'date' => '2026-01-01',
        'original_amount' => '100.00',
        'balance' => '9999.00',
    ]);
    Posting::factory()->forAccount($account)->create([
        'date' => '2026-02-01',
        'original_amount' => '-30.00',
        'balance' => null,
    ]);

    recalculateManualAccount($account);

    expect((float) $account->refresh()->current_balance)->toBe(1070.00)
        ->and($account->last_known_posting_date->toDateString())->toBe('2026-02-01')
        ->and((float) $first->refresh()->balance)->toBe(9999.00);
});

test('a changed opening balance updates the manual total even with no postings', function () {
    $account = manualBalanceAccount(['opening_balance' => '250.00']);

    recalculateManualAccount($account);

    expect((float) $account->refresh()->current_balance)->toBe(250.00)
        ->and($account->last_known_posting_date)->toBeNull();
});

test('an unknown movement makes a manually calculated total unknown', function () {
    $account = manualBalanceAccount([
        'opening_balance' => '100.00',
        'current_balance' => '140.00',
    ]);
    Posting::factory()->forAccount($account)->create(['original_amount' => '50.00']);
    Posting::factory()->forAccount($account)->create(['original_amount' => null]);

    recalculateManualAccount($account);

    expect($account->refresh()->current_balance)->toBeNull();
});

test('an account without an opening balance keeps its imported balance snapshot', function () {
    $account = manualBalanceAccount([
        'opening_balance' => null,
        'current_balance' => '500.00',
        'last_updated_at' => '2026-01-01 12:00:00',
    ]);
    Posting::factory()->forAccount($account)->create(['original_amount' => '-25.00']);

    recalculateManualAccount($account);

    expect((float) $account->refresh()->current_balance)->toBe(500.00)
        ->and($account->last_updated_at->toDateTimeString())->toBe('2026-01-01 12:00:00');
});

test('a connected account keeps its provider balance snapshot', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create([
        'current_balance' => '500.00',
        'opening_balance' => '100.00',
    ]);
    $connection = BankConnection::factory()->for($user)->create();
    BankConnectionAccount::factory()->for($connection)->linkedTo($account)->create();
    Posting::factory()->forAccount($account)->create(['original_amount' => '-25.00']);

    recalculateManualAccount($account);

    expect((float) $account->refresh()->current_balance)->toBe(500.00);
});
