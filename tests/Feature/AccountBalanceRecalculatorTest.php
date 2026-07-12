<?php

use App\Finance\AccountBalanceRecalculator;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\Posting;
use App\Models\User;

function recalculator(): AccountBalanceRecalculator
{
    return app(AccountBalanceRecalculator::class);
}

function makeManualAccount(array $attributes = []): BankAccount
{
    return BankAccount::factory()
        ->for(User::factory()->create())
        ->create(['current_balance' => null, 'opening_balance' => 0, ...$attributes]);
}

test('recalculate rewrites the running balance chain ordered by date then id', function () {
    $account = makeManualAccount();
    $first = Posting::factory()->forAccount($account)->create(['date' => '2026-01-01', 'amount' => '100.00', 'balance' => null]);
    $third = Posting::factory()->forAccount($account)->create(['date' => '2026-02-01', 'amount' => '-30.00', 'balance' => null]);
    $second = Posting::factory()->forAccount($account)->create(['date' => '2026-01-15', 'amount' => '-25.50', 'balance' => null]);

    recalculator()->recalculate($account);

    expect((float) $first->refresh()->balance)->toBe(100.00)
        ->and((float) $second->refresh()->balance)->toBe(74.50)
        ->and((float) $third->refresh()->balance)->toBe(44.50)
        ->and((float) $account->refresh()->current_balance)->toBe(44.50)
        ->and($account->last_known_posting_date->toDateString())->toBe('2026-02-01');
});

test('recalculate starts the chain from the opening balance', function () {
    $account = makeManualAccount(['opening_balance' => '1000.00']);
    Posting::factory()->forAccount($account)->create(['date' => '2026-01-01', 'amount' => '-100.00', 'balance' => null]);

    recalculator()->recalculate($account);

    expect((float) $account->refresh()->current_balance)->toBe(900.00);
});

test('recalculate breaks same-day ties by id', function () {
    $account = makeManualAccount();
    $earlier = Posting::factory()->forAccount($account)->create(['date' => '2026-03-01', 'amount' => '50.00', 'balance' => null]);
    $later = Posting::factory()->forAccount($account)->create(['date' => '2026-03-01', 'amount' => '-20.00', 'balance' => null]);

    recalculator()->recalculate($account);

    expect((float) $earlier->refresh()->balance)->toBe(50.00)
        ->and((float) $later->refresh()->balance)->toBe(30.00);
});

test('recalculate with no postings falls back to the opening balance', function () {
    $account = makeManualAccount(['opening_balance' => '250.00', 'last_known_posting_date' => null]);

    recalculator()->recalculate($account);

    expect((float) $account->refresh()->current_balance)->toBe(250.00)
        ->and($account->last_known_posting_date)->toBeNull();
});

test('recalculate is idempotent and stable across edits', function () {
    $account = makeManualAccount();
    $posting = Posting::factory()->forAccount($account)->create(['date' => '2026-01-01', 'amount' => '100.00', 'balance' => null]);

    recalculator()->recalculate($account);
    expect((float) $account->refresh()->current_balance)->toBe(100.00);

    $posting->update(['amount' => '50.00']);
    recalculator()->recalculate($account);

    expect((float) $posting->refresh()->balance)->toBe(50.00)
        ->and((float) $account->refresh()->current_balance)->toBe(50.00);
});

test('recalculate refuses accounts with a bank connection', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $connection = BankConnection::factory()->for($user)->create();
    BankConnectionAccount::factory()->for($connection)->linkedTo($account)->create();

    recalculator()->recalculate($account);
})->throws(InvalidArgumentException::class);

test('seedOpeningBalanceFromImportedChain infers the balance before the first posting', function () {
    $account = makeManualAccount();
    // Imported chain that opened at 500: +100 => 600, -50 => 550, but the
    // oldest row carries no balance (e.g. a Spiir split artifact).
    Posting::factory()->forAccount($account)->create(['date' => '2026-01-01', 'amount' => '100.00', 'balance' => null]);
    Posting::factory()->forAccount($account)->create(['date' => '2026-01-10', 'amount' => '-50.00', 'balance' => '550.00']);

    recalculator()->seedOpeningBalanceFromImportedChain($account);

    expect((float) $account->refresh()->opening_balance)->toBe(500.00);

    recalculator()->recalculate($account);

    expect((float) $account->refresh()->current_balance)->toBe(550.00);
});

test('seedOpeningBalanceFromImportedChain leaves accounts without balanced postings untouched', function () {
    $account = makeManualAccount(['opening_balance' => 0]);
    Posting::factory()->forAccount($account)->create(['balance' => null]);

    recalculator()->seedOpeningBalanceFromImportedChain($account);

    expect((float) $account->refresh()->opening_balance)->toBe(0.00);
});
