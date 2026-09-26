<?php

use App\Finance\EnableBanking\EnableBankingClient;
use App\Finance\EnableBanking\EnableBankingConnectionManager;
use App\Jobs\SyncBankConnectionAccountJob;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\BankSyncRun;
use App\Models\Posting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\FakeEnableBankingClient;

beforeEach(function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-20 12:00:00'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function enableBankingSessionPayload(): array
{
    return [
        'session_id' => '11111111-2222-4333-8444-555555555555',
        'accounts' => [
            [
                'account_id' => [
                    'iban' => 'DK5000000000000001',
                    'other' => ['identification' => '00000000000001'],
                ],
                'account_servicer' => ['bic_fi' => 'TESTDKKK'],
                'name' => 'Sandbox User',
                'details' => 'Everyday',
                'cash_account_type' => 'CACC',
                'product' => 'Current Account',
                'currency' => 'DKK',
                'uid' => 'c09571a4-3793-43f6-beeb-a10b2d32699b',
                'identification_hash' => 'account-hash-1',
                'identification_hashes' => ['account-hash-1', 'account-name-hash'],
            ],
            [
                'account_id' => [
                    'iban' => 'DK5000000000000002',
                    'other' => ['identification' => '00000000000002'],
                ],
                'account_servicer' => ['bic_fi' => 'TESTDKKK'],
                'name' => 'Sandbox User',
                'details' => 'Shared',
                'cash_account_type' => 'CACC',
                'product' => 'Current Account',
                'currency' => 'DKK',
                'uid' => '8a95e94c-100d-4b4e-b96d-e54d15a63d96',
                'identification_hash' => 'account-hash-2',
                'identification_hashes' => ['account-hash-2', 'account-name-hash'],
            ],
        ],
        'aspsp' => [
            'name' => 'Example Bank',
            'country' => 'DK',
        ],
        'psu_type' => 'personal',
        'access' => [
            'balances' => true,
            'transactions' => true,
            'valid_until' => '2026-09-18T19:14:51Z',
        ],
    ];
}

function enableBankingBalancesPayload(): array
{
    return [
        [
            'name' => 'Accounting balance',
            'balance_amount' => [
                'currency' => 'DKK',
                'amount' => '51339.66',
            ],
            'balance_type' => 'CLBD',
        ],
        [
            'name' => 'Expected balance',
            'balance_amount' => [
                'currency' => 'DKK',
                'amount' => '51339.66',
            ],
            'balance_type' => 'XPCD',
        ],
    ];
}

function enableBankingTransactionsPayload(): array
{
    return [
        [
            'entry_reference' => '2026-06-19-04.21.58.526801',
            'transaction_amount' => [
                'currency' => 'DKK',
                'amount' => '128.00',
            ],
            'credit_debit_indicator' => 'DBIT',
            'status' => 'BOOK',
            'booking_date' => '2026-06-19',
            'value_date' => '2026-06-19',
            'balance_after_transaction' => [
                'currency' => 'DKK',
                'amount' => '51339.66',
            ],
            'remittance_information' => ['Card purchase'],
            'transaction_id' => 'stable-enable-banking-transaction-id',
        ],
        [
            'entry_reference' => '2027-06-30-04.21.58.526801',
            'transaction_amount' => [
                'currency' => 'DKK',
                'amount' => '250.00',
            ],
            'credit_debit_indicator' => 'CRDT',
            'status' => 'BOOK',
            'booking_date' => '2027-06-30',
            'value_date' => '2027-06-30',
            'balance_after_transaction' => [
                'currency' => 'DKK',
                'amount' => '51589.66',
            ],
            'remittance_information' => ['Scheduled transfer'],
            'transaction_id' => 'future-enable-banking-transaction-id',
        ],
    ];
}

test('inertia requests to start a bank connection return an external location response', function () {
    $user = User::factory()->create();

    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient);

    $this->actingAs($user)
        ->withHeader(Header::INERTIA, 'true')
        ->post(route('bank-connections.store'), [
            'aspsp_name' => 'Example Bank',
            'aspsp_country' => 'DK',
        ])
        ->assertStatus(409)
        ->assertHeader(Header::LOCATION, 'https://enablebanking.test/login');
});

test('standard requests to start a bank connection redirect to enable banking', function () {
    $user = User::factory()->create();

    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient);

    $this->actingAs($user)
        ->post(route('bank-connections.store'), [
            'aspsp_name' => 'Example Bank',
            'aspsp_country' => 'DK',
        ])
        ->assertRedirect('https://enablebanking.test/login');
});

test('starting a connection reuses an abandoned pending connection', function () {
    $user = User::factory()->create();
    $pending = BankConnection::factory()->for($user)->create([
        'status' => BankConnection::STATUS_PENDING,
        'state' => 'abandoned-state',
    ]);

    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient);

    $this->actingAs($user)
        ->post(route('bank-connections.store'), [
            'aspsp_name' => 'Example Bank',
            'aspsp_country' => 'DK',
        ])
        ->assertRedirect('https://enablebanking.test/login');

    expect(BankConnection::query()->count())->toBe(1)
        ->and($pending->refresh()->status)->toBe(BankConnection::STATUS_PENDING)
        ->and($pending->state)->not->toBe('abandoned-state');
});

test('pending connections are hidden from the bank accounts index', function () {
    $user = User::factory()->create();
    BankConnection::factory()->for($user)->create([
        'status' => BankConnection::STATUS_PENDING,
    ]);
    $active = BankConnection::factory()->for($user)->create();

    $this->actingAs($user)
        ->get(route('bank-accounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bank-accounts/index')
            ->has('connections', 1)
            ->where('connections.0.id', $active->id));
});

test('a denied authorization callback drops the pending connection', function () {
    $user = User::factory()->create();
    $pending = BankConnection::factory()->for($user)->create([
        'status' => BankConnection::STATUS_PENDING,
        'state' => 'denied-state',
    ]);

    $this->actingAs($user)
        ->get(route('bank-connections.callback', [
            'error' => 'access_denied',
            'state' => 'denied-state',
        ]))
        ->assertRedirect(route('bank-accounts.index'))
        ->assertSessionHas('error');

    expect(BankConnection::query()->whereKey($pending->id)->exists())->toBeFalse();
});

test('stale pending connections are pruned', function () {
    $user = User::factory()->create();
    $stale = BankConnection::factory()->for($user)->create([
        'status' => BankConnection::STATUS_PENDING,
        'created_at' => now()->subDays(3),
    ]);
    $fresh = BankConnection::factory()->for($user)->create([
        'status' => BankConnection::STATUS_PENDING,
    ]);
    $active = BankConnection::factory()->for($user)->create();

    $this->artisan('model:prune', ['--model' => BankConnection::class]);

    expect(BankConnection::query()->whereKey($stale->id)->exists())->toBeFalse()
        ->and(BankConnection::query()->whereKey($fresh->id)->exists())->toBeTrue()
        ->and(BankConnection::query()->whereKey($active->id)->exists())->toBeTrue();
});

test('inertia renewal requests return an external location response', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->renewalRequired()->create();

    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient);

    $this->actingAs($user)
        ->withHeader(Header::INERTIA, 'true')
        ->post(route('bank-connections.renew', $connection))
        ->assertStatus(409)
        ->assertHeader(Header::LOCATION, 'https://enablebanking.test/login');
});

test('sync endpoint dispatches a bank connection account sync job', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo($localAccount)
        ->create();

    Queue::fake();

    $this->actingAs($user)
        ->post(route('bank-connection-accounts.sync', $remoteAccount))
        ->assertRedirect();

    Queue::assertPushed(
        SyncBankConnectionAccountJob::class,
        fn (SyncBankConnectionAccountJob $job): bool => $job->bankConnectionAccount->is($remoteAccount),
    );
});

test('sync command dispatches jobs for linked connection accounts', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create();
    $linkedAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo($localAccount)
        ->create();

    BankConnectionAccount::factory()
        ->for($connection)
        ->create(['user_id' => $user->id]);

    BankConnectionAccount::factory()
        ->for($connection)
        ->create([
            'user_id' => $user->id,
            'status' => BankConnectionAccount::STATUS_IGNORED,
        ]);

    Queue::fake();

    $this->artisan('app:bank-connections:sync')
        ->assertSuccessful();

    Queue::assertPushedTimes(SyncBankConnectionAccountJob::class, 1);
    Queue::assertPushed(
        SyncBankConnectionAccountJob::class,
        fn (SyncBankConnectionAccountJob $job): bool => $job->bankConnectionAccount->is($linkedAccount),
    );
});

test('callback persists an active connection and returned accounts', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create([
        'status' => BankConnection::STATUS_PENDING,
        'state' => 'callback-state',
        'session_id' => null,
        'session_id_hash' => null,
    ]);

    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient(
        session: enableBankingSessionPayload(),
    ));

    $this->actingAs($user)
        ->get(route('bank-connections.callback', [
            'state' => 'callback-state',
            'code' => 'authorization-code',
        ]))
        ->assertRedirect(route('bank-accounts.index', ['setup' => $connection->id]));

    expect($connection->refresh()->status)->toBe(BankConnection::STATUS_ACTIVE)
        ->and($connection->aspsp_name)->toBe('Example Bank')
        ->and($connection->session_id_hash)->toBe(hash('sha256', '11111111-2222-4333-8444-555555555555'))
        ->and($connection->accounts)->toHaveCount(2)
        ->and($connection->accounts()->first()->details)->toBe('Everyday');
});

test('a replayed callback resolves to the already completed connection', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create([
        'status' => BankConnection::STATUS_PENDING,
        'state' => 'replayed-state',
        'session_id' => null,
        'session_id_hash' => null,
    ]);

    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient(
        session: enableBankingSessionPayload(),
    ));

    $callback = fn () => $this->actingAs($user)
        ->get(route('bank-connections.callback', [
            'state' => 'replayed-state',
            'code' => 'authorization-code',
        ]));

    $callback()->assertRedirect(route('bank-accounts.index', ['setup' => $connection->id]));

    // The state was consumed: a replay must not 404 or redo the work.
    $callback()->assertRedirect(route('bank-accounts.index', ['setup' => $connection->id]));

    expect($connection->refresh()->status)->toBe(BankConnection::STATUS_ACTIVE)
        ->and($connection->accounts)->toHaveCount(2);
});

test('re-authorization preserves ignored remote accounts', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create([
        'status' => BankConnection::STATUS_PENDING,
        'state' => 'reauthorization-state',
    ]);
    $ignored = BankConnectionAccount::factory()
        ->for($connection)
        ->create([
            'user_id' => $user->id,
            'status' => BankConnectionAccount::STATUS_IGNORED,
            'external_account_uid' => 'c09571a4-3793-43f6-beeb-a10b2d32699b',
            'external_account_uid_hash' => hash('sha256', 'c09571a4-3793-43f6-beeb-a10b2d32699b'),
        ]);

    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient(
        session: enableBankingSessionPayload(),
    ));

    $this->actingAs($user)
        ->get(route('bank-connections.callback', [
            'state' => 'reauthorization-state',
            'code' => 'authorization-code',
        ]))
        ->assertRedirect(route('bank-accounts.index', ['setup' => $connection->id]));

    expect($ignored->refresh()->status)->toBe(BankConnectionAccount::STATUS_IGNORED)
        ->and($ignored->bank_account_id)->toBeNull();
});

test('remote accounts can be linked to an existing or newly created bank account', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->create(['user_id' => $user->id]);
    $localAccount = BankAccount::factory()->for($user)->create(['name' => 'Everyday']);

    Queue::fake();

    $this->actingAs($user)
        ->patch(route('bank-connection-accounts.update', $remoteAccount), [
            'mode' => 'existing',
            'bank_account_id' => $localAccount->id,
        ])
        ->assertRedirect();

    expect($remoteAccount->refresh()->status)->toBe(BankConnectionAccount::STATUS_LINKED)
        ->and($remoteAccount->bank_account_id)->toBe($localAccount->id);

    Queue::assertPushed(
        SyncBankConnectionAccountJob::class,
        fn (SyncBankConnectionAccountJob $job): bool => $job->bankConnectionAccount->is($remoteAccount),
    );

    $newRemoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patch(route('bank-connection-accounts.update', $newRemoteAccount), [
            'mode' => 'create',
            'name' => 'Example Checking',
        ])
        ->assertRedirect();

    expect($newRemoteAccount->refresh()->status)->toBe(BankConnectionAccount::STATUS_LINKED)
        ->and($newRemoteAccount->bankAccount->user_id)->toBe($user->id)
        ->and($newRemoteAccount->bankAccount->name)->toBe('Example Checking');

    Queue::assertPushed(
        SyncBankConnectionAccountJob::class,
        fn (SyncBankConnectionAccountJob $job): bool => $job->bankConnectionAccount->is($newRemoteAccount),
    );
});

test('only spiir-imported accounts can be linked to an existing bank feed', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->create(['user_id' => $user->id]);
    $manualAccount = BankAccount::factory()->for($user)->create([
        'legacy_id' => BankAccount::MANUAL_LEGACY_ID_PREFIX.'created-in-app',
    ]);

    Queue::fake();

    $this->actingAs($user)
        ->patch(route('bank-connection-accounts.update', $remoteAccount), [
            'mode' => 'existing',
            'bank_account_id' => $manualAccount->id,
        ])
        ->assertSessionHasErrors('bank_account_id');

    $this->actingAs($user)
        ->post(route('bank-connections.accounts.map', $connection), [
            'accounts' => [
                [
                    'id' => $remoteAccount->id,
                    'mode' => 'existing',
                    'bank_account_id' => $manualAccount->id,
                ],
            ],
        ])
        ->assertSessionHasErrors('accounts.0.bank_account_id');

    expect($remoteAccount->refresh()->status)->toBe(BankConnectionAccount::STATUS_UNLINKED);

    Queue::assertNothingPushed();
});

test('linking an account with existing postings sets an import cutoff', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->create(['user_id' => $user->id]);
    $localAccount = BankAccount::factory()->for($user)->create();
    Posting::factory()->forAccount($localAccount)->create(['date' => '2026-06-10']);
    Posting::factory()->forAccount($localAccount)->create(['date' => '2026-05-01']);

    Queue::fake();

    $this->actingAs($user)
        ->patch(route('bank-connection-accounts.update', $remoteAccount), [
            'mode' => 'existing',
            'bank_account_id' => $localAccount->id,
        ])
        ->assertRedirect();

    expect($localAccount->refresh()->ignore_postings_before->toDateString())->toBe('2026-06-11');

    // A pre-existing cutoff is never overwritten.
    $localAccount->update(['ignore_postings_before' => '2026-01-01']);

    $this->actingAs($user)
        ->patch(route('bank-connection-accounts.update', $remoteAccount), [
            'mode' => 'existing',
            'bank_account_id' => $localAccount->id,
        ])
        ->assertRedirect();

    expect($localAccount->refresh()->ignore_postings_before->toDateString())->toBe('2026-01-01');
});

test('a chosen sync history becomes the import cutoff of a created account', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->create(['user_id' => $user->id]);

    Queue::fake();

    $this->actingAs($user)
        ->post(route('bank-connections.accounts.map', $connection), [
            'accounts' => [
                [
                    'id' => $remoteAccount->id,
                    'mode' => 'create',
                    'name' => 'Everyday',
                    'history' => '2y',
                ],
            ],
        ])
        ->assertRedirect();

    expect($remoteAccount->refresh()->bankAccount->ignore_postings_before->toDateString())
        ->toBe('2024-06-20');
});

test('a max sync history leaves a created account without an import cutoff', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->create(['user_id' => $user->id]);

    Queue::fake();

    $this->actingAs($user)
        ->post(route('bank-connections.accounts.map', $connection), [
            'accounts' => [
                [
                    'id' => $remoteAccount->id,
                    'mode' => 'create',
                    'name' => 'Everyday',
                    'history' => 'max',
                ],
            ],
        ])
        ->assertRedirect();

    expect($remoteAccount->refresh()->bankAccount->ignore_postings_before)->toBeNull();
});

test('a chosen sync history never imports before the existing posting history', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->create(['user_id' => $user->id]);
    $localAccount = BankAccount::factory()->for($user)->create();
    Posting::factory()->forAccount($localAccount)->create(['date' => '2026-06-10']);

    Queue::fake();

    // The requested year would reach 2025-06-20, but the stored history
    // already covers postings through 2026-06-10, so the later cutoff wins.
    $this->actingAs($user)
        ->patch(route('bank-connection-accounts.update', $remoteAccount), [
            'mode' => 'existing',
            'bank_account_id' => $localAccount->id,
            'history' => '1y',
        ])
        ->assertRedirect();

    expect($localAccount->refresh()->ignore_postings_before->toDateString())->toBe('2026-06-11');
});

test('linking an account without postings sets no import cutoff', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->create(['user_id' => $user->id]);
    $localAccount = BankAccount::factory()->for($user)->create();

    Queue::fake();

    $this->actingAs($user)
        ->patch(route('bank-connection-accounts.update', $remoteAccount), [
            'mode' => 'existing',
            'bank_account_id' => $localAccount->id,
        ])
        ->assertRedirect();

    expect($localAccount->refresh()->ignore_postings_before)->toBeNull();
});

test('sync skips transactions before the account import cutoff', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create([
        'ignore_postings_before' => '2026-06-20',
    ]);
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo($localAccount)
        ->create();
    $client = new FakeEnableBankingClient(
        balances: enableBankingBalancesPayload(),
        transactions: enableBankingTransactionsPayload(),
    );

    app()->instance(EnableBankingClient::class, $client);

    (new SyncBankConnectionAccountJob($remoteAccount))->handle(app(EnableBankingConnectionManager::class));

    $run = BankSyncRun::query()->latest('id')->firstOrFail();

    // The fake booked transaction is dated 2026-06-19, before the cutoff.
    expect(Posting::query()->count())->toBe(0)
        ->and($run->status)->toBe(BankSyncRun::STATUS_SUCCEEDED)
        ->and($run->new_postings_count)->toBe(0)
        ->and($remoteAccount->refresh()->last_transaction_date->toDateString())->toBe('2026-06-19');
});

test('sync imports past transactions idempotently and skips future scheduled transactions', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo($localAccount)
        ->create();
    $client = new FakeEnableBankingClient(
        balances: enableBankingBalancesPayload(),
        transactions: enableBankingTransactionsPayload(),
    );

    app()->instance(EnableBankingClient::class, $client);

    (new SyncBankConnectionAccountJob($remoteAccount))->handle(app(EnableBankingConnectionManager::class));

    // The first sync asks Enable Banking for the longest available history.
    expect($client->transactionDateFroms)->toBe([null])
        ->and($client->transactionStrategies)->toBe(['longest'])
        ->and(Posting::query()->count())->toBe(1)
        ->and(Posting::query()->where('external_posting_id', 'future-enable-banking-transaction-id')->exists())->toBeFalse();

    $posting = Posting::query()->firstOrFail();

    expect($posting->amount)->toBe('-128.00')
        ->and($posting->source)->toBe(BankConnectionAccount::SOURCE_ENABLE_BANKING)
        ->and($posting->external_posting_id)->toBe('stable-enable-banking-transaction-id')
        ->and($localAccount->refresh()->current_balance)->toBe('51339.66')
        ->and($remoteAccount->refresh()->last_transaction_date->toDateString())->toBe('2026-06-19');

    (new SyncBankConnectionAccountJob($remoteAccount))->handle(app(EnableBankingConnectionManager::class));

    $latestRun = BankSyncRun::query()->latest('id')->firstOrFail();

    expect($client->transactionDateFroms)->toBe([null, '2026-06-18'])
        ->and($client->transactionStrategies)->toBe(['longest', null])
        ->and(Posting::query()->count())->toBe(1)
        ->and($latestRun->duplicate_postings_count)->toBe(1);
});

test('archived accounts are never synced', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $archived = BankAccount::factory()->for($user)->create(['is_active' => false]);
    $archivedRemote = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo($archived)
        ->create();
    $openRemote = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo(BankAccount::factory()->for($user)->create())
        ->create();

    Queue::fake();

    $this->artisan('app:bank-connections:sync')->assertSuccessful();

    Queue::assertPushedTimes(SyncBankConnectionAccountJob::class, 1);
    Queue::assertPushed(
        SyncBankConnectionAccountJob::class,
        fn (SyncBankConnectionAccountJob $job): bool => $job->bankConnectionAccount->is($openRemote),
    );

    Queue::fake();

    $this->actingAs($user)
        ->post(route('bank-connection-accounts.sync', $archivedRemote))
        ->assertUnprocessable();

    $this->actingAs($user)
        ->post(route('bank-connections.sync', $connection))
        ->assertRedirect();

    Queue::assertPushedTimes(SyncBankConnectionAccountJob::class, 1);
    Queue::assertPushed(
        SyncBankConnectionAccountJob::class,
        fn (SyncBankConnectionAccountJob $job): bool => $job->bankConnectionAccount->is($openRemote),
    );

    // A stale job still reaching the manager records a failed run without
    // poisoning the connection status.
    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient);

    (new SyncBankConnectionAccountJob($archivedRemote->refresh()))
        ->handle(app(EnableBankingConnectionManager::class));

    $run = BankSyncRun::query()->latest('id')->firstOrFail();

    expect($run->status)->toBe(BankSyncRun::STATUS_FAILED)
        ->and($run->error_code)->toBe('account_archived')
        ->and($connection->refresh()->status)->toBe(BankConnection::STATUS_ACTIVE);
});

test('archived connections stop syncing but keep their accounts and can be reopened', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo(BankAccount::factory()->for($user)->create())
        ->create();

    $this->actingAs($user)
        ->post(route('bank-connections.archive', $connection))
        ->assertRedirect();

    expect($connection->refresh()->status)->toBe(BankConnection::STATUS_ARCHIVED);

    Queue::fake();

    $this->artisan('app:bank-connections:sync')->assertSuccessful();

    Queue::assertNothingPushed();

    $this->actingAs($user)
        ->post(route('bank-connections.sync', $connection))
        ->assertUnprocessable();

    $this->actingAs($user)
        ->post(route('bank-connection-accounts.sync', $remoteAccount))
        ->assertUnprocessable();

    // A stale job still reaching the manager fails the run, not the links.
    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient);

    (new SyncBankConnectionAccountJob($remoteAccount->refresh()))
        ->handle(app(EnableBankingConnectionManager::class));

    $run = BankSyncRun::query()->latest('id')->firstOrFail();

    expect($run->status)->toBe(BankSyncRun::STATUS_FAILED)
        ->and($run->error_code)->toBe('connection_archived')
        ->and($remoteAccount->refresh()->bank_account_id)->not->toBeNull();

    $this->actingAs($user)
        ->post(route('bank-connections.unarchive', $connection))
        ->assertRedirect();

    expect($connection->refresh()->status)->toBe(BankConnection::STATUS_ACTIVE);
});

test('re-adding the same bank login merges the new consent into the existing connection', function () {
    $user = User::factory()->create();
    $existing = BankConnection::factory()->for($user)->create([
        'status' => BankConnection::STATUS_ARCHIVED,
    ]);
    $localAccount = BankAccount::factory()->for($user)->create();
    // No shared uid/identification-hash with the session payload — the IBAN
    // overlap alone must be enough to recognize the account.
    $remoteAccount = BankConnectionAccount::factory()
        ->for($existing)
        ->linkedTo($localAccount)
        ->create(['iban' => 'DK5000000000000001']);

    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient(
        session: enableBankingSessionPayload(),
    ));

    // Re-adding the bank starts a fresh authorization — which login it is can
    // only be known once the session returns its accounts.
    $this->actingAs($user)->post(route('bank-connections.store'), [
        'aspsp_name' => 'Example Bank',
        'aspsp_country' => 'DK',
    ]);

    $pending = BankConnection::query()->latest('id')->firstOrFail();

    expect(BankConnection::query()->count())->toBe(2)
        ->and($pending->status)->toBe(BankConnection::STATUS_PENDING);

    Queue::fake();

    $this->actingAs($user)
        ->get(route('bank-connections.callback', [
            'state' => $pending->state,
            'code' => 'authorization-code',
        ]))
        ->assertRedirect(route('bank-accounts.index', ['setup' => $existing->id]));

    // The consent folded into the archived connection: it's active again, the
    // pending row is discarded, and the IBAN-matched account kept its link.
    expect(BankConnection::query()->count())->toBe(1)
        ->and($existing->refresh()->status)->toBe(BankConnection::STATUS_ACTIVE)
        ->and($existing->accounts()->count())->toBe(2)
        ->and($remoteAccount->refresh()->bank_account_id)->toBe($localAccount->id)
        ->and($remoteAccount->status)->toBe(BankConnectionAccount::STATUS_LINKED);
});

test('a different bank login at the same bank creates a separate connection', function () {
    $user = User::factory()->create();
    $existing = BankConnection::factory()->for($user)->create([
        'aspsp_name' => 'Example Bank',
        'aspsp_country' => 'DK',
    ]);
    BankConnectionAccount::factory()
        ->for($existing)
        ->linkedTo(BankAccount::factory()->for($user)->create())
        ->create(['iban' => 'DK9999999999999999']);

    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient(
        session: enableBankingSessionPayload(),
    ));

    $this->actingAs($user)->post(route('bank-connections.store'), [
        'aspsp_name' => 'Example Bank',
        'aspsp_country' => 'DK',
    ]);

    $pending = BankConnection::query()->latest('id')->firstOrFail();

    $this->actingAs($user)
        ->get(route('bank-connections.callback', [
            'state' => $pending->state,
            'code' => 'authorization-code',
        ]))
        ->assertRedirect(route('bank-accounts.index', ['setup' => $pending->id]));

    expect(BankConnection::query()->count())->toBe(2)
        ->and($pending->refresh()->status)->toBe(BankConnection::STATUS_ACTIVE)
        ->and($pending->accounts()->count())->toBe(2)
        ->and($existing->refresh()->accounts()->count())->toBe(1);
});

test('renewing a connection keeps its status while consent is pending', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create([
        'status' => BankConnection::STATUS_RENEWAL_REQUIRED,
    ]);

    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient);

    $this->actingAs($user)
        ->withHeader(Header::INERTIA, 'true')
        ->post(route('bank-connections.renew', $connection))
        ->assertStatus(409);

    expect($connection->refresh()->status)->toBe(BankConnection::STATUS_RENEWAL_REQUIRED)
        ->and($connection->state)->not->toBeNull();
});

test('expired connections are marked renewable and exposed to the user', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->renewalRequired()->create();
    $localAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo($localAccount)
        ->create();

    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient);

    (new SyncBankConnectionAccountJob($remoteAccount))->handle(app(EnableBankingConnectionManager::class));

    expect($connection->refresh()->status)->toBe(BankConnection::STATUS_RENEWAL_REQUIRED)
        ->and($remoteAccount->refresh()->status)->toBe(BankConnectionAccount::STATUS_RENEWAL_REQUIRED)
        ->and(BankSyncRun::query()->firstOrFail()->status)->toBe(BankSyncRun::STATUS_FAILED);

    $this->actingAs($user)
        ->get(route('bank-accounts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bank-accounts/index')
            ->where('accounts.0.connection_accounts.0.needs_renewal', true));
});

test('renewed callbacks preserve existing account links', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->renewalRequired()->create([
        'state' => 'renewal-state',
    ]);
    $localAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo($localAccount)
        ->create([
            'status' => BankConnectionAccount::STATUS_RENEWAL_REQUIRED,
            'external_account_uid' => 'c09571a4-3793-43f6-beeb-a10b2d32699b',
            'external_account_uid_hash' => hash('sha256', 'c09571a4-3793-43f6-beeb-a10b2d32699b'),
        ]);

    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient(
        session: enableBankingSessionPayload(),
    ));

    Queue::fake();

    $this->actingAs($user)
        ->get(route('bank-connections.callback', [
            'state' => 'renewal-state',
            'code' => 'authorization-code',
        ]))
        // The renewal returns a second, previously unseen remote account, so
        // the setup wizard opens for it.
        ->assertRedirect(route('bank-accounts.index', ['setup' => $connection->id]));

    expect($connection->refresh()->status)->toBe(BankConnection::STATUS_ACTIVE)
        ->and($remoteAccount->refresh()->bank_account_id)->toBe($localAccount->id)
        ->and($remoteAccount->status)->toBe(BankConnectionAccount::STATUS_LINKED);

    Queue::assertPushed(
        SyncBankConnectionAccountJob::class,
        fn (SyncBankConnectionAccountJob $job): bool => $job->bankConnectionAccount->is($remoteAccount),
    );
});

test('sync reconciles a matching balance and records the figures', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo($localAccount)
        ->create();
    $client = new FakeEnableBankingClient(
        balances: enableBankingBalancesPayload(),
        transactions: enableBankingTransactionsPayload(),
    );

    app()->instance(EnableBankingClient::class, $client);

    (new SyncBankConnectionAccountJob($remoteAccount))->handle(app(EnableBankingConnectionManager::class));

    $run = BankSyncRun::query()->latest('id')->firstOrFail();

    expect($run->status)->toBe(BankSyncRun::STATUS_SUCCEEDED)
        ->and($run->balance_matches)->toBeTrue()
        ->and($run->expected_balance)->toBe('51339.66')
        ->and($run->computed_balance)->toBe('51339.66')
        ->and($run->hasBalanceMismatch())->toBeFalse()
        ->and($run->balanceDelta())->toBe('0.00');
});

test('sync flags a balance mismatch without failing the run', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo($localAccount)
        ->create();

    $balances = enableBankingBalancesPayload();
    $balances[0]['balance_amount']['amount'] = '51439.66';

    $client = new FakeEnableBankingClient(
        balances: $balances,
        transactions: enableBankingTransactionsPayload(),
    );

    app()->instance(EnableBankingClient::class, $client);

    (new SyncBankConnectionAccountJob($remoteAccount))->handle(app(EnableBankingConnectionManager::class));

    $run = BankSyncRun::query()->latest('id')->firstOrFail();

    expect($run->status)->toBe(BankSyncRun::STATUS_SUCCEEDED)
        ->and($run->balance_matches)->toBeFalse()
        ->and($run->expected_balance)->toBe('51439.66')
        ->and($run->computed_balance)->toBe('51339.66')
        ->and($run->hasBalanceMismatch())->toBeTrue()
        ->and($run->balanceDelta())->toBe('100.00');
});

test('sync accepts the closing checkpoint when same-day ids are out of bank order', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($localAccount)->create();

    foreach ([
        ['2026-06-18', '100.00', '100.00'],
        ['2026-06-19', '-30.00', '120.00'],
        ['2026-06-19', '50.00', '150.00'],
    ] as [$date, $amount, $balance]) {
        Posting::factory()->forAccount($localAccount)->create([
            'source' => BankConnectionAccount::SOURCE_ENABLE_BANKING,
            'external_account_uid_hash' => $remoteAccount->external_account_uid_hash,
            'date' => $date,
            'amount' => $amount,
            'balance' => $balance,
        ]);
    }

    $balances = enableBankingBalancesPayload();
    $balances[0]['balance_amount']['amount'] = '120.00';
    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient(balances: $balances));

    (new SyncBankConnectionAccountJob($remoteAccount))->handle(app(EnableBankingConnectionManager::class));

    $run = BankSyncRun::query()->latest('id')->firstOrFail();
    expect($run->balance_matches)->toBeTrue()
        ->and($run->expected_balance)->toBe('120.00')
        ->and($run->computed_balance)->toBe('120.00');
});

test('sync leaves reconciliation null when the bank sends neither balances nor checkpoints', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo($localAccount)
        ->create();

    $transactions = enableBankingTransactionsPayload();
    unset($transactions[0]['balance_after_transaction']);

    $client = new FakeEnableBankingClient(
        balances: [],
        transactions: $transactions,
    );

    app()->instance(EnableBankingClient::class, $client);

    (new SyncBankConnectionAccountJob($remoteAccount))->handle(app(EnableBankingConnectionManager::class));

    $run = BankSyncRun::query()->latest('id')->firstOrFail();

    expect($run->status)->toBe(BankSyncRun::STATUS_SUCCEEDED)
        ->and($run->expected_balance)->toBeNull()
        ->and($run->computed_balance)->toBeNull()
        ->and($run->balance_matches)->toBeNull()
        ->and($run->hasBalanceMismatch())->toBeFalse()
        ->and($run->balanceDelta())->toBeNull();
});

test('sync derives running balances from the booked balance when the bank sends none', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create(['opening_balance' => 0]);
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo($localAccount)
        ->create();

    $transactions = enableBankingTransactionsPayload();
    unset($transactions[0]['balance_after_transaction']);

    $client = new FakeEnableBankingClient(
        balances: enableBankingBalancesPayload(),
        transactions: $transactions,
    );

    app()->instance(EnableBankingClient::class, $client);

    (new SyncBankConnectionAccountJob($remoteAccount))->handle(app(EnableBankingConnectionManager::class));

    // The sole booked posting is -128.00 and the bank books 51339.66, so the
    // implied opening is 51467.66 and the chain lands exactly on the CLBD.
    $posting = Posting::query()->firstOrFail();

    expect(Posting::query()->count())->toBe(1)
        ->and($posting->balance)->toBe('51339.66')
        ->and($localAccount->refresh()->opening_balance)->toBe('51467.66')
        ->and($localAccount->current_balance)->toBe('51339.66');

    $run = BankSyncRun::query()->latest('id')->firstOrFail();

    expect($run->computed_balance)->toBe('51339.66')
        ->and($run->balance_matches)->toBeTrue();
});

test('sync extends the derived chain for later postings without balances', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create(['opening_balance' => 0]);
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo($localAccount)
        ->create();

    $transactions = enableBankingTransactionsPayload();
    unset($transactions[0]['balance_after_transaction']);

    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient(
        balances: enableBankingBalancesPayload(),
        transactions: $transactions,
    ));

    (new SyncBankConnectionAccountJob($remoteAccount))->handle(app(EnableBankingConnectionManager::class));

    // A later sync brings one new, checkpoint-less posting: -50.00 on top of
    // the derived 51339.66 anchor, and the bank now books 51289.66.
    $newerTransaction = [
        'entry_reference' => '2026-06-20-10.00.00.000001',
        'transaction_amount' => [
            'currency' => 'DKK',
            'amount' => '50.00',
        ],
        'credit_debit_indicator' => 'DBIT',
        'status' => 'BOOK',
        'booking_date' => '2026-06-20',
        'value_date' => '2026-06-20',
        'remittance_information' => ['New card purchase'],
        'transaction_id' => 'new-enable-banking-transaction-id',
    ];
    $balances = enableBankingBalancesPayload();
    $balances[0]['balance_amount']['amount'] = '51289.66';

    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient(
        balances: $balances,
        transactions: [$newerTransaction],
    ));

    (new SyncBankConnectionAccountJob($remoteAccount))->handle(app(EnableBankingConnectionManager::class));

    $newPosting = Posting::query()
        ->where('external_posting_id', 'new-enable-banking-transaction-id')
        ->firstOrFail();

    expect($newPosting->balance)->toBe('51289.66')
        ->and($localAccount->refresh()->opening_balance)->toBe('51467.66');

    $run = BankSyncRun::query()->latest('id')->firstOrFail();

    expect($run->computed_balance)->toBe('51289.66')
        ->and($run->expected_balance)->toBe('51289.66')
        ->and($run->balance_matches)->toBeTrue();
});

test('connection sync endpoint dispatches jobs for every linked account', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $firstAccount = BankAccount::factory()->for($user)->create();
    $secondAccount = BankAccount::factory()->for($user)->create();
    $firstLinked = BankConnectionAccount::factory()->for($connection)->linkedTo($firstAccount)->create();
    $secondLinked = BankConnectionAccount::factory()->for($connection)->linkedTo($secondAccount)->create();
    BankConnectionAccount::factory()->for($connection)->create(['user_id' => $user->id]);

    Queue::fake();

    $this->actingAs($user)
        ->post(route('bank-connections.sync', $connection))
        ->assertRedirect();

    Queue::assertPushedTimes(SyncBankConnectionAccountJob::class, 2);
    Queue::assertPushed(
        SyncBankConnectionAccountJob::class,
        fn (SyncBankConnectionAccountJob $job): bool => $job->bankConnectionAccount->is($firstLinked),
    );
    Queue::assertPushed(
        SyncBankConnectionAccountJob::class,
        fn (SyncBankConnectionAccountJob $job): bool => $job->bankConnectionAccount->is($secondLinked),
    );
});

test('connection sync endpoint is authorized to the owner', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $connection = BankConnection::factory()->for($owner)->create();

    Queue::fake();

    $this->actingAs($intruder)
        ->post(route('bank-connections.sync', $connection))
        ->assertForbidden();

    Queue::assertNothingPushed();
});

test('removing a connection keeps local accounts and postings but deletes remote accounts and runs', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($localAccount)->create();
    Posting::factory()->forAccount($localAccount)->create();
    BankSyncRun::factory()->forConnectionAccount($remoteAccount->refresh())->create();

    $this->actingAs($user)
        ->delete(route('bank-connections.destroy', $connection))
        ->assertRedirect();

    expect(BankConnection::query()->count())->toBe(0)
        ->and(BankConnectionAccount::query()->count())->toBe(0)
        ->and(BankSyncRun::query()->count())->toBe(0)
        ->and(BankAccount::query()->whereKey($localAccount->id)->exists())->toBeTrue()
        ->and(Posting::query()->where('bank_account_id', $localAccount->id)->count())->toBe(1);
});

test('removing a connection anchors the detached accounts as manual and derives their balances', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create(['current_balance' => null, 'opening_balance' => 0]);
    BankConnectionAccount::factory()->for($connection)->linkedTo($localAccount)->create();
    // Synced chain that opened at 500: +100 => 600, -50 => 550.
    Posting::factory()->forAccount($localAccount)->create(['date' => '2026-01-01', 'amount' => '100.00', 'balance' => '600.00']);
    Posting::factory()->forAccount($localAccount)->create(['date' => '2026-01-10', 'amount' => '-50.00', 'balance' => '550.00']);

    $this->actingAs($user)
        ->delete(route('bank-connections.destroy', $connection))
        ->assertRedirect();

    expect((float) $localAccount->refresh()->opening_balance)->toBe(500.00)
        ->and((float) $localAccount->current_balance)->toBe(550.00);
});

test('ignoring a linked remote account anchors the detached account as manual', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create(['current_balance' => null, 'opening_balance' => 0]);
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($localAccount)->create();
    Posting::factory()->forAccount($localAccount)->create(['date' => '2026-01-01', 'amount' => '100.00', 'balance' => '600.00']);

    $this->actingAs($user)
        ->patch(route('bank-connection-accounts.update', $remoteAccount), ['mode' => 'ignore'])
        ->assertRedirect();

    expect($remoteAccount->refresh()->status)->toBe(BankConnectionAccount::STATUS_IGNORED)
        ->and((float) $localAccount->refresh()->opening_balance)->toBe(500.00)
        ->and((float) $localAccount->current_balance)->toBe(600.00);
});

test('removing a connection is authorized to the owner', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $connection = BankConnection::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->delete(route('bank-connections.destroy', $connection))
        ->assertForbidden();

    expect(BankConnection::query()->count())->toBe(1);
});

test('batch mapping links, creates, and ignores remote accounts in one request', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $remoteCreate = BankConnectionAccount::factory()->for($connection)->create(['user_id' => $user->id]);
    $remoteExisting = BankConnectionAccount::factory()->for($connection)->create(['user_id' => $user->id]);
    $remoteIgnore = BankConnectionAccount::factory()->for($connection)->create(['user_id' => $user->id]);
    $localAccount = BankAccount::factory()->for($user)->create(['name' => 'Everyday']);

    Queue::fake();

    $this->actingAs($user)
        ->post(route('bank-connections.accounts.map', $connection), [
            'accounts' => [
                ['id' => $remoteCreate->id, 'mode' => 'create', 'name' => 'Example Checking'],
                ['id' => $remoteExisting->id, 'mode' => 'existing', 'bank_account_id' => $localAccount->id],
                ['id' => $remoteIgnore->id, 'mode' => 'ignore'],
            ],
        ])
        ->assertRedirect()
        ->assertSessionHas('status', 'Bank accounts set up.');

    expect($remoteCreate->refresh()->status)->toBe(BankConnectionAccount::STATUS_LINKED)
        ->and($remoteCreate->bankAccount->name)->toBe('Example Checking')
        ->and($remoteCreate->bankAccount->user_id)->toBe($user->id)
        ->and($remoteExisting->refresh()->status)->toBe(BankConnectionAccount::STATUS_LINKED)
        ->and($remoteExisting->bank_account_id)->toBe($localAccount->id)
        ->and($remoteIgnore->refresh()->status)->toBe(BankConnectionAccount::STATUS_IGNORED)
        ->and($remoteIgnore->bank_account_id)->toBeNull();

    Queue::assertPushedTimes(SyncBankConnectionAccountJob::class, 2);
    Queue::assertPushed(
        SyncBankConnectionAccountJob::class,
        fn (SyncBankConnectionAccountJob $job): bool => $job->bankConnectionAccount->is($remoteCreate),
    );
    Queue::assertPushed(
        SyncBankConnectionAccountJob::class,
        fn (SyncBankConnectionAccountJob $job): bool => $job->bankConnectionAccount->is($remoteExisting),
    );
});

test('batch mapping rejects a local account already linked to another remote account', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create();
    BankConnectionAccount::factory()->for($connection)->linkedTo($localAccount)->create();
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->create(['user_id' => $user->id]);

    Queue::fake();

    $this->actingAs($user)
        ->post(route('bank-connections.accounts.map', $connection), [
            'accounts' => [
                ['id' => $remoteAccount->id, 'mode' => 'existing', 'bank_account_id' => $localAccount->id],
            ],
        ])
        ->assertSessionHasErrors('accounts.0.bank_account_id');

    expect($remoteAccount->refresh()->status)->toBe(BankConnectionAccount::STATUS_UNLINKED);

    Queue::assertNothingPushed();
});

test('batch mapping rejects remote accounts from another connection and other users', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $otherConnection = BankConnection::factory()->for($user)->create();
    $foreignRemote = BankConnectionAccount::factory()->for($otherConnection)->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->post(route('bank-connections.accounts.map', $connection), [
            'accounts' => [
                ['id' => $foreignRemote->id, 'mode' => 'ignore'],
            ],
        ])
        ->assertSessionHasErrors('accounts.0.id');

    expect($foreignRemote->refresh()->status)->toBe(BankConnectionAccount::STATUS_UNLINKED);

    $intruder = User::factory()->create();
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->create(['user_id' => $user->id]);

    $this->actingAs($intruder)
        ->post(route('bank-connections.accounts.map', $connection), [
            'accounts' => [
                ['id' => $remoteAccount->id, 'mode' => 'ignore'],
            ],
        ])
        ->assertForbidden();
});

test('an unknown callback state redirects with an error instead of failing', function () {
    $user = User::factory()->create();

    // An expired or superseded authorization link (e.g. from a pending row
    // that was reused or pruned) should send the user back with a message.
    $this->actingAs($user)
        ->get(route('bank-connections.callback', [
            'state' => 'expired-state',
            'code' => 'authorization-code',
        ]))
        ->assertRedirect(route('bank-accounts.index'))
        ->assertSessionHas('error');
});

test('renewing while authorizing a different bank login keeps the original connection', function () {
    $user = User::factory()->create();
    $renewing = BankConnection::factory()->for($user)->create([
        'status' => BankConnection::STATUS_RENEWAL_REQUIRED,
    ]);
    $other = BankConnection::factory()->for($user)->create();
    BankConnectionAccount::factory()
        ->for($other)
        ->linkedTo(BankAccount::factory()->for($user)->create())
        ->create(['iban' => 'DK5000000000000001']);

    app()->instance(EnableBankingClient::class, new FakeEnableBankingClient(
        session: enableBankingSessionPayload(),
    ));

    $this->actingAs($user)
        ->post(route('bank-connections.renew', $renewing));

    expect($renewing->refresh()->state)->not->toBeNull();

    // The session's accounts belong to the other connection, so the consent
    // folds into it — but the renewed connection must not be deleted.
    $this->actingAs($user)
        ->get(route('bank-connections.callback', [
            'state' => $renewing->state,
            'code' => 'authorization-code',
        ]))
        ->assertRedirect(route('bank-accounts.index', ['setup' => $other->id]));

    expect(BankConnection::query()->whereKey($renewing->id)->exists())->toBeTrue()
        ->and($renewing->refresh()->state)->toBeNull()
        ->and($renewing->status)->toBe(BankConnection::STATUS_RENEWAL_REQUIRED)
        ->and($other->refresh()->status)->toBe(BankConnection::STATUS_ACTIVE)
        ->and($other->accounts()->count())->toBe(2);
});

test('mapping accounts on an archived connection links without dispatching sync jobs', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create([
        'status' => BankConnection::STATUS_ARCHIVED,
    ]);
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->create(['user_id' => $user->id]);

    Queue::fake();

    $this->actingAs($user)
        ->post(route('bank-connections.accounts.map', $connection), [
            'accounts' => [
                ['id' => $remoteAccount->id, 'mode' => 'create', 'name' => 'Everyday'],
            ],
        ])
        ->assertRedirect();

    expect($remoteAccount->refresh()->status)->toBe(BankConnectionAccount::STATUS_LINKED)
        ->and($remoteAccount->bank_account_id)->not->toBeNull();

    Queue::assertNothingPushed();
});

test('archived local accounts cannot be linked to a bank feed', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->create(['user_id' => $user->id]);
    $archivedAccount = BankAccount::factory()->for($user)->create(['is_active' => false]);

    Queue::fake();

    $this->actingAs($user)
        ->post(route('bank-connections.accounts.map', $connection), [
            'accounts' => [
                [
                    'id' => $remoteAccount->id,
                    'mode' => 'existing',
                    'bank_account_id' => $archivedAccount->id,
                ],
            ],
        ])
        ->assertSessionHasErrors('accounts.0.bank_account_id');

    expect($remoteAccount->refresh()->status)->toBe(BankConnectionAccount::STATUS_UNLINKED);
});
