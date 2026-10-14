<?php

use App\Jobs\ConvertReportingAmounts;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

function manualPostingAccount(User $user, array $attributes = []): BankAccount
{
    return BankAccount::factory()->for($user)->create(['opening_balance' => '0.00', ...$attributes]);
}

function manualPostingPayload(array $overrides = []): array
{
    return [
        'date' => '2026-07-01',
        'description' => 'Rent July',
        'amount' => '-4500.00',
        'categorized_subcategory_id' => null,
        'comment' => null,
        ...$overrides,
    ];
}

test('postings can be created manually on accounts without a bank connection', function () {
    $user = User::factory()->create();
    $account = manualPostingAccount($user, ['currency' => 'DKK']);
    $subcategory = Subcategory::factory()->create();

    $this->actingAs($user)
        ->post(route('bank-accounts.postings.store', $account), manualPostingPayload([
            'categorized_subcategory_id' => $subcategory->id,
            'comment' => 'Paid from shared budget',
        ]))
        ->assertRedirect();

    $posting = Posting::query()->firstOrFail();

    expect($posting->bank_account_id)->toBe($account->id)
        ->and($posting->user_id)->toBe($user->id)
        ->and($posting->legacy_id)->toBeNull()
        ->and($posting->source)->toBe('manual')
        ->and($posting->currency)->toBe('DKK')
        ->and($posting->date->toDateString())->toBe('2026-07-01')
        ->and($posting->description)->toBe('Rent July')
        ->and((float) $posting->amount)->toBe(-4500.00)
        ->and($posting->categorized_subcategory_id)->toBe($subcategory->id)
        ->and($posting->comment)->toBe('Paid from shared budget')
        ->and($account->refresh()->last_known_posting_date->toDateString())->toBe('2026-07-01');
});

test('manual posting creation validates its input', function () {
    $user = User::factory()->create();
    $account = manualPostingAccount($user);

    $this->actingAs($user)
        ->from(route('bank-accounts.show', $account))
        ->post(route('bank-accounts.postings.store', $account), [
            'date' => 'not-a-date',
            'description' => '',
            'amount' => 'abc',
        ])
        ->assertSessionHasErrors(['date', 'description', 'amount']);

    expect(Posting::query()->count())->toBe(0);
});

test('manual postings can be updated', function () {
    $user = User::factory()->create();
    $account = manualPostingAccount($user);
    $posting = Posting::factory()->forAccount($account)->uncategorized()->create(['date' => '2026-06-01']);
    $subcategory = Subcategory::factory()->create();

    $this->actingAs($user)
        ->patch(route('bank-accounts.postings.update', [$account, $posting]), manualPostingPayload([
            'date' => '2026-06-15',
            'description' => 'Groceries',
            'amount' => '-250.75',
            'categorized_subcategory_id' => $subcategory->id,
        ]))
        ->assertRedirect();

    $posting->refresh();

    expect($posting->date->toDateString())->toBe('2026-06-15')
        ->and($posting->description)->toBe('Groceries')
        ->and((float) $posting->amount)->toBe(-250.75)
        ->and($posting->categorized_subcategory_id)->toBe($subcategory->id)
        ->and($account->refresh()->last_known_posting_date->toDateString())->toBe('2026-06-15');
});

test('foreign-currency manual postings dispatch the conversion job when created', function () {
    Queue::fake();
    $user = User::factory()->create();
    $account = manualPostingAccount($user, ['currency' => 'USD']);

    $this->actingAs($user)
        ->post(route('bank-accounts.postings.store', $account), manualPostingPayload())
        ->assertRedirect();

    Queue::assertPushed(ConvertReportingAmounts::class, fn (ConvertReportingAmounts $job): bool => $job->userId === $user->id);
});

test('editing a foreign-currency manual posting dispatches the conversion job', function () {
    Queue::fake();
    $user = User::factory()->create();
    $account = manualPostingAccount($user, ['currency' => 'USD']);
    $posting = Posting::factory()->forAccount($account)->uncategorized()->create();

    $this->actingAs($user)
        ->patch(route('bank-accounts.postings.update', [$account, $posting]), manualPostingPayload(['amount' => '-10.00']))
        ->assertRedirect();

    Queue::assertPushed(ConvertReportingAmounts::class, fn (ConvertReportingAmounts $job): bool => $job->userId === $user->id);
});

test('same-currency manual postings skip the conversion job', function () {
    Queue::fake();
    $user = User::factory()->create();
    $account = manualPostingAccount($user);

    $this->actingAs($user)
        ->post(route('bank-accounts.postings.store', $account), manualPostingPayload())
        ->assertRedirect();

    Queue::assertNotPushed(ConvertReportingAmounts::class);
});

test('manual postings can be deleted', function () {
    $user = User::factory()->create();
    $account = manualPostingAccount($user);
    $kept = Posting::factory()->forAccount($account)->create(['date' => '2026-05-01']);
    $deleted = Posting::factory()->forAccount($account)->create(['date' => '2026-06-01']);
    $kept->update(['counter_entry_posting_id' => $deleted->id]);

    $this->actingAs($user)
        ->delete(route('bank-accounts.postings.destroy', [$account, $deleted]))
        ->assertRedirect();

    expect(Posting::query()->pluck('id')->all())->toBe([$kept->id])
        ->and($kept->refresh()->counter_entry_posting_id)->toBeNull()
        ->and(Posting::onlyTrashed()->pluck('id')->all())->toBe([$deleted->id])
        ->and($account->refresh()->last_known_posting_date->toDateString())->toBe('2026-05-01');
});

test('manual posting changes recalculate only the account total', function () {
    $user = User::factory()->create();
    $account = manualPostingAccount($user, ['current_balance' => null, 'opening_balance' => '100.00']);

    $this->actingAs($user)->post(route('bank-accounts.postings.store', $account), manualPostingPayload([
        'date' => '2026-06-01',
        'amount' => '250.00',
    ]));
    $this->actingAs($user)->post(route('bank-accounts.postings.store', $account), manualPostingPayload([
        'date' => '2026-06-10',
        'amount' => '-50.00',
    ]));

    [$first, $second] = Posting::query()->orderBy('date')->get();

    expect($first->balance)->toBeNull()
        ->and($second->balance)->toBeNull()
        ->and((float) $account->refresh()->current_balance)->toBe(300.00);

    $this->actingAs($user)->patch(
        route('bank-accounts.postings.update', [$account, $first]),
        manualPostingPayload(['date' => '2026-06-01', 'amount' => '100.00']),
    );

    expect((float) $account->refresh()->current_balance)->toBe(150.00)
        ->and($first->refresh()->balance)->toBeNull();

    $this->actingAs($user)->delete(route('bank-accounts.postings.destroy', [$account, $second]));

    expect((float) $account->refresh()->current_balance)->toBe(200.00);
});

test('manual postings move an unanchored account balance', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create([
        'opening_balance' => null,
        'current_balance' => '500.00',
    ]);

    $this->actingAs($user)->post(route('bank-accounts.postings.store', $account), manualPostingPayload([
        'amount' => '-100.00',
    ]));

    $posting = Posting::query()->firstOrFail();

    expect((float) $account->refresh()->current_balance)->toBe(400.00);

    $this->actingAs($user)->patch(
        route('bank-accounts.postings.update', [$account, $posting]),
        manualPostingPayload(['amount' => '-150.00']),
    );

    expect((float) $account->refresh()->current_balance)->toBe(350.00);

    $this->actingAs($user)->delete(route('bank-accounts.postings.destroy', [$account, $posting]));

    expect((float) $account->refresh()->current_balance)->toBe(500.00);
});

test('postings cannot be managed manually on accounts with a bank connection', function () {
    $user = User::factory()->create();
    $account = manualPostingAccount($user);
    $connection = BankConnection::factory()->for($user)->create();
    BankConnectionAccount::factory()->for($connection)->linkedTo($account)->create();
    $posting = Posting::factory()->forAccount($account)->create();

    $this->actingAs($user)
        ->post(route('bank-accounts.postings.store', $account), manualPostingPayload())
        ->assertForbidden();

    $this->actingAs($user)
        ->patch(route('bank-accounts.postings.update', [$account, $posting]), manualPostingPayload())
        ->assertForbidden();

    $this->actingAs($user)
        ->delete(route('bank-accounts.postings.destroy', [$account, $posting]))
        ->assertForbidden();

    expect(Posting::query()->count())->toBe(1);
});

test('manual postings are only manageable by the account owner', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $account = manualPostingAccount($owner);
    $posting = Posting::factory()->forAccount($account)->create();

    $this->actingAs($intruder)
        ->post(route('bank-accounts.postings.store', $account), manualPostingPayload())
        ->assertForbidden();

    $this->actingAs($intruder)
        ->patch(route('bank-accounts.postings.update', [$account, $posting]), manualPostingPayload())
        ->assertForbidden();

    $this->actingAs($intruder)
        ->delete(route('bank-accounts.postings.destroy', [$account, $posting]))
        ->assertForbidden();

    expect(Posting::query()->count())->toBe(1);
});

test('manual posting routes are scoped to the bank account', function () {
    $user = User::factory()->create();
    $account = manualPostingAccount($user);
    $otherAccount = manualPostingAccount($user);
    $posting = Posting::factory()->forAccount($otherAccount)->create();

    $this->actingAs($user)
        ->patch(route('bank-accounts.postings.update', [$account, $posting]), manualPostingPayload())
        ->assertNotFound();

    $this->actingAs($user)
        ->delete(route('bank-accounts.postings.destroy', [$account, $posting]))
        ->assertNotFound();
});

test('bank account detail exposes manual state and categories for manual accounts', function () {
    $user = User::factory()->create();
    $account = manualPostingAccount($user);
    $subcategory = Subcategory::factory()->create();
    Posting::factory()->forAccount($account)->categorizedAs($subcategory)->create(['comment' => 'Note']);

    $this->actingAs($user)
        ->get(route('bank-accounts.show', $account))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bank-accounts/show')
            ->where('account.is_manual', true)
            ->where('postings.data.0.comment', 'Note')
            ->where('postings.data.0.categorized_subcategory_id', $subcategory->id)
            ->has('categories', 1));
});

test('bank account detail marks connected accounts as not manual but still offers categories', function () {
    $user = User::factory()->create();
    $account = manualPostingAccount($user);
    $connection = BankConnection::factory()->for($user)->create();
    BankConnectionAccount::factory()->for($connection)->linkedTo($account)->create();
    Subcategory::factory()->create();

    $this->actingAs($user)
        ->get(route('bank-accounts.show', $account))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('account.is_manual', false)
            ->has('categories', 1));
});

test('manual postings can be created and updated with the extraordinary flag', function () {
    $user = User::factory()->create();
    $account = manualPostingAccount($user);

    $this->actingAs($user)
        ->post(route('bank-accounts.postings.store', $account), manualPostingPayload([
            'is_extraordinary' => true,
        ]))
        ->assertRedirect();

    $posting = Posting::query()->firstOrFail();

    expect($posting->is_extraordinary)->toBeTrue();

    $this->actingAs($user)
        ->patch(route('bank-accounts.postings.update', [$account, $posting]), manualPostingPayload([
            'is_extraordinary' => false,
        ]))
        ->assertRedirect();

    expect($posting->refresh()->is_extraordinary)->toBeFalse();
});
