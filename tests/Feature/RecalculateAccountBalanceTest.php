<?php

use App\Actions\RecalculateAccountBalance;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\Posting;
use App\Models\User;

function recalculateManualAccount(BankAccount $account, string $delta = '0'): void
{
    app(RecalculateAccountBalance::class)->handle($account, $delta);
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

test('manual writes nudge an unanchored account balance by their delta', function () {
    $account = manualBalanceAccount([
        'opening_balance' => null,
        'current_balance' => '500.00',
    ]);
    Posting::factory()->forAccount($account)->create(['original_amount' => '-25.00']);

    recalculateManualAccount($account, '-25.00');

    expect((float) $account->refresh()->current_balance)->toBe(475.00);
});

test('an unanchored account without any figure totals its known movements', function () {
    $account = manualBalanceAccount([
        'opening_balance' => null,
        'current_balance' => null,
    ]);
    Posting::factory()->forAccount($account)->create(['date' => '2026-01-01', 'original_amount' => '40.00']);
    Posting::factory()->forAccount($account)->create(['date' => '2026-02-01', 'original_amount' => '-25.00']);

    recalculateManualAccount($account);

    expect((float) $account->refresh()->current_balance)->toBe(15.00)
        ->and($account->last_known_posting_date->toDateString())->toBe('2026-02-01');
});

test('an unanchored account without a figure stays unknown when a movement is unknown', function () {
    $account = manualBalanceAccount([
        'opening_balance' => null,
        'current_balance' => null,
    ]);
    Posting::factory()->forAccount($account)->create(['original_amount' => '40.00']);
    Posting::factory()->forAccount($account)->create(['original_amount' => null]);

    recalculateManualAccount($account);

    expect($account->refresh()->current_balance)->toBeNull();
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
