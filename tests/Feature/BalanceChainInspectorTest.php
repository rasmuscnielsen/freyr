<?php

use App\Finance\BalanceChainInspector;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\Posting;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia as Assert;

function inspector(): BalanceChainInspector
{
    return app(BalanceChainInspector::class);
}

function inspectableAccount(array $attributes = []): BankAccount
{
    return BankAccount::factory()
        ->for(User::factory()->create())
        ->create(['current_balance' => null, ...$attributes]);
}

function chainPosting(BankAccount $account, string $date, string $amount, ?string $balance, string $description = 'Posting'): Posting
{
    return Posting::factory()->forAccount($account)->create([
        'date' => $date,
        'amount' => $amount,
        'balance' => $balance,
        'description' => $description,
    ]);
}

test('a consistent chain reports no breaks', function () {
    $account = inspectableAccount(['current_balance' => '70.00']);
    chainPosting($account, '2026-01-01', '100.00', '100.00');
    chainPosting($account, '2026-01-02', '-30.00', '70.00');

    $audit = inspector()->inspect($account);

    expect($audit['is_consistent'])->toBeTrue()
        ->and($audit['breaks'])->toBe([])
        ->and($audit['total_delta'])->toBe('0.00')
        ->and($audit['checked_postings'])->toBe(2);
});

test('a day that closes short of the expectation is reported as a gap', function () {
    $account = inspectableAccount(['current_balance' => '60.00']);
    chainPosting($account, '2026-01-01', '100.00', '100.00');
    chainPosting($account, '2026-01-02', '-30.00', '60.00');

    $audit = inspector()->inspect($account);

    expect($audit['is_consistent'])->toBeFalse()
        ->and($audit['breaks'])->toHaveCount(1)
        ->and($audit['breaks'][0]['kind'])->toBe('gap')
        ->and($audit['breaks'][0]['date'])->toBe('2026-01-02')
        ->and($audit['breaks'][0]['delta'])->toBe('-10.00');
});

test('a duplicated posting that explains the delta is flagged as possible duplicate', function () {
    $account = inspectableAccount(['current_balance' => '70.00']);
    chainPosting($account, '2026-01-01', '100.00', '100.00');
    chainPosting($account, '2026-01-02', '-30.00', null, 'Coffee');
    chainPosting($account, '2026-01-02', '-30.00', '70.00', 'Coffee');

    $audit = inspector()->inspect($account);

    expect($audit['breaks'])->toHaveCount(1)
        ->and($audit['breaks'][0]['kind'])->toBe('possible_duplicate')
        ->and($audit['breaks'][0]['postings'])->toHaveCount(2)
        ->and($audit['breaks'][0]['postings'][0]['description'])->toBe('Coffee');
});

test('a day whose own rows contradict each other is a suspect day', function () {
    $account = inspectableAccount(['current_balance' => '75.00']);
    chainPosting($account, '2026-01-01', '100.00', '100.00');
    chainPosting($account, '2026-01-02', '-10.00', '80.00');
    chainPosting($account, '2026-01-02', '-20.00', '75.00');

    $audit = inspector()->inspect($account);

    expect($audit['breaks'])->toHaveCount(1)
        ->and($audit['breaks'][0]['kind'])->toBe('suspect_day')
        ->and($audit['breaks'][0]['postings'])->toHaveCount(2);
});

test('rows stored out of intra-day order are clean when the day still closes', function () {
    $account = inspectableAccount(['current_balance' => '120.00']);
    chainPosting($account, '2026-01-01', '100.00', '100.00');
    chainPosting($account, '2026-01-02', '-30.00', '120.00');
    chainPosting($account, '2026-01-02', '50.00', '150.00');

    $audit = inspector()->inspect($account);

    expect($audit['is_consistent'])->toBeTrue()
        ->and($audit['closing_balance'])->toBe('120.00');
});

test('account warning agrees with the audit when same-day ids are out of bank order', function () {
    $account = inspectableAccount(['current_balance' => '120.00']);
    $connection = BankConnection::factory()->for($account->user)->create();
    BankConnectionAccount::factory()->for($connection)->linkedTo($account)->create();
    chainPosting($account, '2026-01-01', '100.00', '100.00');
    chainPosting($account, '2026-01-02', '-30.00', '120.00');
    chainPosting($account, '2026-01-02', '50.00', '150.00');

    $this->actingAs($account->user)
        ->get(route('bank-accounts.show', $account))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('account.balance_diff', null)
            ->missing('balanceAudit'));
});

test('days without stored balances are folded into the next checkpoint', function () {
    $account = inspectableAccount(['current_balance' => '70.00']);
    chainPosting($account, '2026-01-01', '100.00', '100.00');
    chainPosting($account, '2026-01-02', '-10.00', null);
    chainPosting($account, '2026-01-03', '-20.00', '70.00');

    $audit = inspector()->inspect($account);

    expect($audit['is_consistent'])->toBeTrue();
});

test('the closing balance still includes days after the break display limit', function () {
    $account = inspectableAccount(['current_balance' => '121.00']);
    chainPosting($account, '2026-01-01', '100.00', '100.00');

    for ($day = 2; $day <= 22; $day++) {
        chainPosting($account, sprintf('2026-01-%02d', $day), '0.00', (string) (99 + $day).'.00');
    }

    $audit = inspector()->inspect($account);

    expect($audit['closing_balance'])->toBe('121.00')
        ->and($audit['total_delta'])->toBe('0.00')
        ->and($audit['breaks'])->toHaveCount(20);
});

test('a bank balance ahead of the newest posting is reported as tail lag', function () {
    $account = inspectableAccount(['current_balance' => '200.00']);
    chainPosting($account, '2026-01-01', '100.00', '100.00');
    chainPosting($account, '2026-01-02', '-30.00', '70.00');

    $audit = inspector()->inspect($account);

    expect($audit['is_consistent'])->toBeFalse()
        ->and($audit['breaks'])->toHaveCount(1)
        ->and($audit['breaks'][0]['kind'])->toBe('tail_lag')
        ->and($audit['breaks'][0]['delta'])->toBe('130.00')
        ->and($audit['total_delta'])->toBe('130.00');
});

test('the account page exposes the balance diff and lazily loads the audit for connected accounts', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create(['current_balance' => '60.00']);
    $connection = BankConnection::factory()->for($user)->create();
    BankConnectionAccount::factory()->for($connection)->linkedTo($account)->create();
    chainPosting($account, '2026-01-01', '100.00', '100.00');

    $this->actingAs($user)
        ->get(route('bank-accounts.show', $account))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('account.balance_diff', '-40.00')
            ->missing('balanceAudit'));

    $this->actingAs($user)
        ->get(route('bank-accounts.show', $account), [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => 'bank-accounts/show',
            'X-Inertia-Partial-Data' => 'balanceAudit',
            'X-Inertia-Version' => Inertia::getVersion(),
        ])
        ->assertOk()
        ->assertJsonPath('props.balanceAudit.is_consistent', false)
        ->assertJsonPath('props.balanceAudit.breaks.0.kind', 'tail_lag');
});

test('the account page reports no balance diff for manual accounts', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create(['current_balance' => '100.00']);
    chainPosting($account, '2026-01-01', '100.00', '100.00');

    $this->actingAs($user)
        ->get(route('bank-accounts.show', $account))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('account.balance_diff', null));
});
