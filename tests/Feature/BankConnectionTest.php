<?php

use App\Jobs\SyncBankConnectionAccount;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\BankSyncRun;
use App\Models\Posting;
use App\Models\User;
use App\Services\EnableBanking\Client;
use App\Services\EnableBanking\ConnectionManager;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
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

    app()->instance(Client::class, new FakeEnableBankingClient);

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

    app()->instance(Client::class, new FakeEnableBankingClient);

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

    app()->instance(Client::class, new FakeEnableBankingClient);

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

    app()->instance(Client::class, new FakeEnableBankingClient);

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
        SyncBankConnectionAccount::class,
        fn (SyncBankConnectionAccount $job): bool => $job->bankConnectionAccount->is($remoteAccount),
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

    Queue::assertPushedTimes(SyncBankConnectionAccount::class, 1);
    Queue::assertPushed(
        SyncBankConnectionAccount::class,
        fn (SyncBankConnectionAccount $job): bool => $job->bankConnectionAccount->is($linkedAccount),
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

    app()->instance(Client::class, new FakeEnableBankingClient(
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

    app()->instance(Client::class, new FakeEnableBankingClient(
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

    app()->instance(Client::class, new FakeEnableBankingClient(
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
        SyncBankConnectionAccount::class,
        fn (SyncBankConnectionAccount $job): bool => $job->bankConnectionAccount->is($remoteAccount),
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
        SyncBankConnectionAccount::class,
        fn (SyncBankConnectionAccount $job): bool => $job->bankConnectionAccount->is($newRemoteAccount),
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

    app()->instance(Client::class, $client);

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

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

    app()->instance(Client::class, $client);

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    // The first sync asks Enable Banking for the longest available history.
    expect($client->transactionDateFroms)->toBe([null, '2026-06-13'])
        ->and($client->transactionStrategies)->toBe(['longest', null])
        ->and(Posting::query()->count())->toBe(1)
        ->and(Posting::query()->where('external_posting_id', 'future-enable-banking-transaction-id')->exists())->toBeFalse();

    $posting = Posting::query()->firstOrFail();

    expect($posting->amount)->toBe('-128.00')
        ->and($posting->legacy_id)->toBeNull()
        ->and($posting->source)->toBe(BankConnectionAccount::SOURCE_ENABLE_BANKING)
        ->and($posting->external_posting_id)->toBe('2026-06-19-04.21.58.526801')
        ->and($localAccount->refresh()->current_balance)->toBe('51339.66')
        ->and($remoteAccount->refresh()->last_transaction_date->toDateString())->toBe('2026-06-19');

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    $latestRun = BankSyncRun::query()->latest('id')->firstOrFail();

    expect($client->transactionDateFroms)->toBe([null, '2026-06-13', '2026-06-18'])
        ->and($client->transactionStrategies)->toBe(['longest', null, null])
        ->and(Posting::query()->count())->toBe(1)
        ->and($latestRun->duplicate_postings_count)->toBe(1);
});

test('a re-sent transaction the user deleted stays deleted without failing the sync', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()
        ->for($connection)
        ->linkedTo($localAccount)
        ->create();

    app()->instance(Client::class, new FakeEnableBankingClient(
        balances: enableBankingBalancesPayload(),
        transactions: enableBankingTransactionsPayload(),
    ));

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));
    Posting::query()->firstOrFail()->delete();

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    $latestRun = BankSyncRun::query()->latest('id')->firstOrFail();

    expect($latestRun->status)->not->toBe('failed')
        ->and($latestRun->duplicate_postings_count)->toBe(1)
        ->and(Posting::query()->count())->toBe(0)
        ->and(Posting::onlyTrashed()->count())->toBe(1);
});

test('first sync supplements longest history with recent booked transactions', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($localAccount)->create();

    $past = enableBankingTransactionsPayload()[0];
    $recentPast = $past;
    $recentPast['transaction_id'] = 'new-provider-id';
    $future = function (string $reference, string $amount, string $balance): array {
        return [
            'entry_reference' => $reference,
            'transaction_id' => 'provider-'.$reference,
            'transaction_amount' => ['currency' => 'DKK', 'amount' => $amount],
            'credit_debit_indicator' => 'DBIT',
            'status' => 'BOOK',
            'booking_date' => '2026-06-22',
            'balance_after_transaction' => ['currency' => 'DKK', 'amount' => $balance],
        ];
    };
    $client = new FakeEnableBankingClient(
        balances: [[
            'balance_type' => 'CLBD',
            'balance_amount' => ['currency' => 'DKK', 'amount' => '49853.11'],
        ]],
        transactions: [$past],
        recentTransactions: [
            $recentPast,
            $future('large-booked', '1405.00', '49853.11'),
            $future('small-booked', '81.55', '51258.11'),
        ],
    );
    app()->instance(Client::class, $client);

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    $run = BankSyncRun::query()->latest('id')->firstOrFail();

    expect($client->transactionStrategies)->toBe(['longest', null])
        ->and($client->transactionDateFroms)->toBe([null, '2026-06-13'])
        ->and($run->new_postings_count)->toBe(3)
        ->and(Posting::query()->count())->toBe(3)
        ->and(Posting::query()->where('external_posting_id', $past['entry_reference'])->firstOrFail()->extra_data['transaction_id'])->toBe('new-provider-id');

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    expect($client->transactionStrategies)->toBe(['longest', null, null])
        ->and($client->transactionDateFroms)->toBe([null, '2026-06-13', '2026-06-19']);
});

test('first sync keeps longest history when the recent period is unavailable', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($localAccount)->create();
    $client = new class(balances: enableBankingBalancesPayload(), transactions: [enableBankingTransactionsPayload()[0]]) extends FakeEnableBankingClient
    {
        public function transactions(string $accountUid, ?CarbonInterface $dateFrom, ?string $strategy = null): array
        {
            if ($strategy === null) {
                throw new RequestException(new Response(new GuzzleResponse(400, [], '{"error":"WRONG_TRANSACTIONS_PERIOD"}')));
            }

            return parent::transactions($accountUid, $dateFrom, $strategy);
        }
    };
    app()->instance(Client::class, $client);

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    expect(BankSyncRun::query()->latest('id')->firstOrFail()->status)->toBe(BankSyncRun::STATUS_SUCCEEDED)
        ->and(Posting::query()->count())->toBe(1);
});

test('sync imports future booked transactions only when their balances chain to CLBD', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create(['opening_balance' => '92390.10']);
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($localAccount)->create();

    $future = function (string $reference, string $status, string $amount, string $balance): array {
        return [
            'entry_reference' => $reference,
            'transaction_id' => 'mutable-'.$reference,
            'transaction_amount' => ['currency' => 'DKK', 'amount' => $amount],
            'credit_debit_indicator' => 'DBIT',
            'status' => $status,
            'booking_date' => '2026-06-22',
            'balance_after_transaction' => ['currency' => 'DKK', 'amount' => $balance],
        ];
    };
    $transactions = [
        $future('small-booked', 'BOOK', '81.55', '92308.55'),
        $future('large-booked', 'BOOK', '1405.00', '90903.55'),
        $future('scheduled-booked', 'BOOK', '20.00', '90883.55'),
        $future('pending', 'PDNG', '30.00', '90903.55'),
    ];
    $client = new FakeEnableBankingClient(
        balances: [[
            'balance_type' => 'CLBD',
            'balance_amount' => ['currency' => 'DKK', 'amount' => '90903.55'],
        ]],
        transactions: $transactions,
    );
    app()->instance(Client::class, $client);

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    expect(Posting::query()->pluck('external_posting_id')->all())->toEqualCanonicalizing(['small-booked', 'large-booked'])
        ->and($remoteAccount->refresh()->last_transaction_date->toDateString())->toBe('2026-06-22');

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    expect($client->transactionDateFroms)->toBe([null, '2026-06-13', '2026-06-19'])
        ->and(Posting::query()->count())->toBe(2);

    $finalized = array_map(function (array $transaction): array {
        $transaction['booking_date'] = '2026-06-20';
        $transaction['transaction_id'] = 'final-'.$transaction['entry_reference'];

        return $transaction;
    }, array_slice($transactions, 0, 2));
    app()->instance(Client::class, new FakeEnableBankingClient(
        balances: [[
            'balance_type' => 'CLBD',
            'balance_amount' => ['currency' => 'DKK', 'amount' => '90903.55'],
        ]],
        transactions: $finalized,
    ));

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    expect(Posting::query()->count())->toBe(2)
        ->and(Posting::query()->whereDate('date', '2026-06-20')->count())->toBe(2);
});

test('sync updates an existing transaction by entry reference when its transaction id changes', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($localAccount)->create();
    $transaction = enableBankingTransactionsPayload()[0];
    Posting::factory()->for($user)->for($localAccount)->create([
        'source' => BankConnectionAccount::SOURCE_ENABLE_BANKING,
        'external_posting_id' => 'old-transaction-id',
        'legacy_id' => 'enable-banking:historic-account-hash:old-transaction-id',
        'extra_data' => ['entry_reference' => $transaction['entry_reference']],
        'balance' => '500.00',
    ]);
    $remoteAccount->update([
        'external_account_uid' => 'rotated-provider-account-id',
        'external_account_uid_hash' => hash('sha256', 'rotated-provider-account-id'),
    ]);
    $transaction['transaction_id'] = 'new-transaction-id';
    unset($transaction['balance_after_transaction']);
    app()->instance(Client::class, new FakeEnableBankingClient(
        balances: enableBankingBalancesPayload(),
        transactions: [$transaction],
    ));

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    $posting = Posting::query()->firstOrFail();

    expect(Posting::query()->count())->toBe(1)
        ->and($posting->external_posting_id)->toBe($transaction['entry_reference'])
        ->and($posting->balance)->toBeNull()
        ->and($posting->legacy_id)->toBe('enable-banking:historic-account-hash:old-transaction-id')
        ->and(BankSyncRun::query()->latest('id')->firstOrFail()->updated_postings_count)->toBe(1);
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

    Queue::assertPushedTimes(SyncBankConnectionAccount::class, 1);
    Queue::assertPushed(
        SyncBankConnectionAccount::class,
        fn (SyncBankConnectionAccount $job): bool => $job->bankConnectionAccount->is($openRemote),
    );

    Queue::fake();

    $this->actingAs($user)
        ->post(route('bank-connection-accounts.sync', $archivedRemote))
        ->assertUnprocessable();

    $this->actingAs($user)
        ->post(route('bank-connections.sync', $connection))
        ->assertRedirect();

    Queue::assertPushedTimes(SyncBankConnectionAccount::class, 1);
    Queue::assertPushed(
        SyncBankConnectionAccount::class,
        fn (SyncBankConnectionAccount $job): bool => $job->bankConnectionAccount->is($openRemote),
    );

    // A stale job still reaching the manager records a failed run without
    // poisoning the connection status.
    app()->instance(Client::class, new FakeEnableBankingClient);

    (new SyncBankConnectionAccount($archivedRemote->refresh()))
        ->handle(app(ConnectionManager::class));

    $run = BankSyncRun::query()->latest('id')->firstOrFail();

    expect($run->status)->toBe(BankSyncRun::STATUS_FAILED)
        ->and($run->error_code)->toBe('account_archived')
        ->and($connection->refresh()->status)->toBe(BankConnection::STATUS_ACTIVE);
});

test('sync skips a bank account deleted while transactions are fetched', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $bankAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($bankAccount)->create();
    $client = new FakeEnableBankingClient(
        balances: enableBankingBalancesPayload(),
        transactions: [enableBankingTransactionsPayload()[0]],
        onTransactions: function () use ($remoteAccount, $bankAccount): void {
            DB::transaction(function () use ($remoteAccount, $bankAccount): void {
                $remoteAccount->update(['status' => BankConnectionAccount::STATUS_UNLINKED]);
                $bankAccount->delete();
            });
        },
    );
    app()->instance(Client::class, $client);

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    $run = BankSyncRun::query()->latest('id')->firstOrFail();
    expect($run->status)->toBe(BankSyncRun::STATUS_SKIPPED)
        ->and($run->error_code)->toBe('account_unlinked')
        ->and($connection->refresh()->status)->toBe(BankConnection::STATUS_ACTIVE)
        ->and($remoteAccount->refresh()->status)->toBe(BankConnectionAccount::STATUS_UNLINKED)
        ->and(Posting::query()->count())->toBe(0);
});

test('provider failure after an account is deleted does not fail the connection', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $bankAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($bankAccount)->create();
    $client = new FakeEnableBankingClient(
        balances: enableBankingBalancesPayload(),
        onTransactions: function () use ($remoteAccount, $bankAccount): void {
            DB::transaction(function () use ($remoteAccount, $bankAccount): void {
                $remoteAccount->update(['status' => BankConnectionAccount::STATUS_UNLINKED]);
                $bankAccount->delete();
            });

            throw new RuntimeException('provider failure');
        },
    );
    app()->instance(Client::class, $client);

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    expect(BankSyncRun::query()->latest('id')->firstOrFail()->status)->toBe(BankSyncRun::STATUS_SKIPPED)
        ->and($connection->refresh()->status)->toBe(BankConnection::STATUS_ACTIVE)
        ->and($connection->failure_message)->toBeNull();
});

test('a failed sync stores a safe message while retaining encrypted diagnostic detail', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $bankAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($bankAccount)->create();
    $client = new class extends FakeEnableBankingClient
    {
        public function balances(string $accountUid): array
        {
            throw new RuntimeException('SQLSTATE[23503] secret-transfer-data');
        }
    };
    app()->instance(Client::class, $client);

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    $run = BankSyncRun::query()->latest('id')->firstOrFail();
    expect($connection->refresh()->failure_message)->toBe('Bank sync failed. Please try again.')
        ->and($run->error_message)->toBe('Bank sync failed. Please try again.')
        ->and($run->raw_error['message'])->toContain('secret-transfer-data');
});

test('a provider request error does not require renewal of unexpired consent', function (int $status, string $error) {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create([
        'access_valid_until' => now()->addDays(86),
    ]);
    $bankAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($bankAccount)->create();
    app()->instance(Client::class, new FakeEnableBankingClient(
        balances: enableBankingBalancesPayload(),
        onTransactions: function () use ($status, $error): void {
            throw new RequestException(new Response(new GuzzleResponse($status, [], json_encode([
                'error' => $error,
                'message' => 'Provider diagnostic detail',
            ]))));
        },
    ));

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    expect($connection->refresh()->needsRenewal())->toBeFalse();
    expect($remoteAccount->refresh()->status)->toBe(BankConnectionAccount::STATUS_LINKED);
    $run = BankSyncRun::query()->latest('id')->firstOrFail();
    expect($run->error_code)->toBe($error)
        ->and($run->raw_error['http_status'])->toBe($status)
        ->and($run->raw_error['response_body'])->toContain('Provider diagnostic detail');
})->with([
    'invalid period' => [400, 'WRONG_TRANSACTIONS_PERIOD'],
    'bank error' => [400, 'ASPSP_ERROR'],
    'application authentication' => [401, 'UNAUTHORIZED_ACCESS'],
    'application permissions' => [403, 'ACCESS_DENIED'],
    'missing account' => [404, 'ACCOUNT_DOES_NOT_EXIST'],
]);

test('an unusable provider session requires renewal even before its expected expiry', function (string $error) {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create([
        'access_valid_until' => now()->addDays(86),
    ]);
    $bankAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($bankAccount)->create();
    $client = new FakeEnableBankingClient(
        onTransactions: function () use ($error): void {
            throw new RequestException(new Response(new GuzzleResponse(401, [], json_encode(['error' => $error]))));
        },
    );
    app()->instance(Client::class, $client);

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    expect($connection->refresh()->needsRenewal())->toBeTrue();
    expect($remoteAccount->refresh()->status)->toBe(BankConnectionAccount::STATUS_RENEWAL_REQUIRED);
    expect(BankSyncRun::query()->latest('id')->firstOrFail()->error_code)->toBe($error);
})->with([
    'EXPIRED_SESSION',
    'CLOSED_SESSION',
    'REVOKED_SESSION',
    'SESSION_DOES_NOT_EXIST',
]);

test('a failed bank import rolls back postings written earlier in the same sync', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $bankAccount = BankAccount::factory()->for($user)->create();
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($bankAccount)->create();
    $first = enableBankingTransactionsPayload()[0];
    $second = $first;
    $second['entry_reference'] = 'second-booked';
    $second['transaction_id'] = 'second-provider-id';
    $second['transaction_amount']['amount'] = '10.00';
    $second['balance_after_transaction']['amount'] = '51329.66';
    Posting::creating(function (Posting $posting): void {
        if ($posting->external_posting_id === 'second-booked') {
            throw new RuntimeException('Synthetic posting write failure.');
        }
    });
    app()->instance(Client::class, new FakeEnableBankingClient(
        balances: enableBankingBalancesPayload(),
        transactions: [$first, $second],
    ));

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    expect(BankSyncRun::query()->latest('id')->firstOrFail()->status)->toBe(BankSyncRun::STATUS_FAILED)
        ->and(Posting::query()->where('external_posting_id', $first['entry_reference'])->exists())->toBeFalse()
        ->and(Posting::query()->count())->toBe(0);
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
    app()->instance(Client::class, new FakeEnableBankingClient);

    (new SyncBankConnectionAccount($remoteAccount->refresh()))
        ->handle(app(ConnectionManager::class));

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

    app()->instance(Client::class, new FakeEnableBankingClient(
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

    app()->instance(Client::class, new FakeEnableBankingClient(
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

    app()->instance(Client::class, new FakeEnableBankingClient);

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

    app()->instance(Client::class, new FakeEnableBankingClient);

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

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

    app()->instance(Client::class, new FakeEnableBankingClient(
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
        SyncBankConnectionAccount::class,
        fn (SyncBankConnectionAccount $job): bool => $job->bankConnectionAccount->is($remoteAccount),
    );
});

test('sync stores provider balance snapshots without synthesizing missing posting balances', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create([
        'current_balance' => '123.45',
        'opening_balance' => null,
    ]);
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($localAccount)->create();
    $transactions = enableBankingTransactionsPayload();
    $transactions[1]['booking_date'] = '2026-06-18';
    unset($transactions[1]['balance_after_transaction']);

    app()->instance(Client::class, new FakeEnableBankingClient(
        balances: enableBankingBalancesPayload(),
        transactions: $transactions,
    ));

    (new SyncBankConnectionAccount($remoteAccount))->handle(app(ConnectionManager::class));

    $withProviderBalance = Posting::query()->where('external_posting_id', $transactions[0]['entry_reference'])->firstOrFail();
    $withoutProviderBalance = Posting::query()->where('external_posting_id', $transactions[1]['entry_reference'])->firstOrFail();

    expect((float) $localAccount->refresh()->current_balance)->toBe(51339.66)
        ->and($localAccount->opening_balance)->toBeNull()
        ->and((float) $withProviderBalance->balance)->toBe(51339.66)
        ->and($withoutProviderBalance->balance)->toBeNull()
        ->and(BankSyncRun::query()->latest('id')->firstOrFail()->status)->toBe(BankSyncRun::STATUS_SUCCEEDED);
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

    Queue::assertPushedTimes(SyncBankConnectionAccount::class, 2);
    Queue::assertPushed(
        SyncBankConnectionAccount::class,
        fn (SyncBankConnectionAccount $job): bool => $job->bankConnectionAccount->is($firstLinked),
    );
    Queue::assertPushed(
        SyncBankConnectionAccount::class,
        fn (SyncBankConnectionAccount $job): bool => $job->bankConnectionAccount->is($secondLinked),
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

test('removing a connection preserves the provider snapshot without inferring an opening balance', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create(['current_balance' => '550.00', 'opening_balance' => '0.00']);
    BankConnectionAccount::factory()->for($connection)->linkedTo($localAccount)->create();
    // Keep source balances as imported; they are not used to infer an opening.
    Posting::factory()->forAccount($localAccount)->create(['date' => '2026-01-01', 'amount' => '100.00', 'original_amount' => '100.00', 'balance' => '600.00']);
    Posting::factory()->forAccount($localAccount)->create(['date' => '2026-01-10', 'amount' => '-50.00', 'original_amount' => '-50.00', 'balance' => '550.00']);

    $this->actingAs($user)
        ->delete(route('bank-connections.destroy', $connection))
        ->assertRedirect();

    expect($localAccount->refresh()->opening_balance)->toBeNull()
        ->and((float) $localAccount->current_balance)->toBe(550.00)
        ->and($localAccount->postings()->orderBy('date')->firstOrFail()->balance)->toBe('600.00');
});

test('ignoring a linked remote account preserves its current snapshot and clears the opening', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create();
    $localAccount = BankAccount::factory()->for($user)->create(['current_balance' => '600.00', 'opening_balance' => '0.00']);
    $remoteAccount = BankConnectionAccount::factory()->for($connection)->linkedTo($localAccount)->create();
    Posting::factory()->forAccount($localAccount)->create(['date' => '2026-01-01', 'amount' => '100.00', 'original_amount' => '100.00', 'balance' => '600.00']);

    $this->actingAs($user)
        ->patch(route('bank-connection-accounts.update', $remoteAccount), ['mode' => 'ignore'])
        ->assertRedirect();

    expect($remoteAccount->refresh()->status)->toBe(BankConnectionAccount::STATUS_IGNORED)
        ->and($localAccount->refresh()->opening_balance)->toBeNull()
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

    Queue::assertPushedTimes(SyncBankConnectionAccount::class, 2);
    Queue::assertPushed(
        SyncBankConnectionAccount::class,
        fn (SyncBankConnectionAccount $job): bool => $job->bankConnectionAccount->is($remoteCreate),
    );
    Queue::assertPushed(
        SyncBankConnectionAccount::class,
        fn (SyncBankConnectionAccount $job): bool => $job->bankConnectionAccount->is($remoteExisting),
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

test('bank callback does not display an arbitrary provider error description', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('bank-connections.callback', [
            'state' => 'denied-state',
            'error' => 'access_denied',
            'error_description' => 'SQLSTATE[23503] secret-provider-data',
        ]))
        ->assertRedirect(route('bank-accounts.index'))
        ->assertSessionHas('error', 'Bank sign-in was cancelled. Please try again.');
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

    app()->instance(Client::class, new FakeEnableBankingClient(
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

test('reference-less transactions survive provider reconnects and gain references without losing edits', function () {
    $user = User::factory()->create();
    $local = BankAccount::factory()->for($user)->create();
    $remote = BankConnectionAccount::factory()->for(BankConnection::factory()->for($user)->create())->linkedTo($local)->create();
    $transaction = enableBankingTransactionsPayload()[0];
    unset($transaction['entry_reference']);
    app()->instance(Client::class, new FakeEnableBankingClient(transactions: [$transaction]));
    $run = app(ConnectionManager::class)->syncAccount($remote);
    expect($run->status)->toBe(BankSyncRun::STATUS_SUCCEEDED);
    $posting = Posting::query()->sole();
    expect($posting->external_posting_id)->toBeNull();
    $posting->update(['description' => 'My edited description', 'comment' => 'Keep me', 'custom_date' => '2026-06-18']);
    $remote->update(['external_account_uid' => 'new-consent-account', 'external_account_uid_hash' => hash('sha256', 'new-consent-account')]);
    $transaction['transaction_id'] = 'changed-detail-id';
    app()->instance(Client::class, new FakeEnableBankingClient(transactions: [$transaction, $transaction]));
    expect(app(ConnectionManager::class)->syncAccount($remote)->status)->toBe(BankSyncRun::STATUS_SUCCEEDED);
    expect(Posting::query()->count())->toBe(1);
    $transaction['entry_reference'] = 'now-referenced';
    app()->instance(Client::class, new FakeEnableBankingClient(transactions: [$transaction]));
    expect(app(ConnectionManager::class)->syncAccount($remote)->status)->toBe(BankSyncRun::STATUS_SUCCEEDED);
    expect(Posting::query()->sole()->id)->toBe($posting->id);
    expect($posting->refresh()->external_posting_id)->toBe('now-referenced')
        ->and($posting->description)->toBe('My edited description')
        ->and($posting->comment)->toBe('Keep me')
        ->and($posting->custom_date->toDateString())->toBe('2026-06-18');
    unset($transaction['entry_reference']);
    app()->instance(Client::class, new FakeEnableBankingClient(transactions: [$transaction]));
    expect(app(ConnectionManager::class)->syncAccount($remote)->status)->toBe(BankSyncRun::STATUS_SUCCEEDED);
    expect(Posting::query()->sole()->external_posting_id)->toBe('now-referenced');
});

test('distinct bank references preserve identical same-day payments', function () {
    $user = User::factory()->create();
    $local = BankAccount::factory()->for($user)->create();
    $remote = BankConnectionAccount::factory()->for(BankConnection::factory()->for($user)->create())->linkedTo($local)->create();
    $first = enableBankingTransactionsPayload()[0];
    $second = $first;
    $second['entry_reference'] = 'different-reference';
    app()->instance(Client::class, new FakeEnableBankingClient(transactions: [$first, $second]));
    expect(app(ConnectionManager::class)->syncAccount($remote)->status)->toBe(BankSyncRun::STATUS_SUCCEEDED);
    expect(Posting::query()->count())->toBe(2);
});

test('conflicting repeated bank references fail the entire import instead of overwriting a payment', function () {
    $user = User::factory()->create();
    $local = BankAccount::factory()->for($user)->create();
    $remote = BankConnectionAccount::factory()->for(BankConnection::factory()->for($user)->create())->linkedTo($local)->create();
    $first = enableBankingTransactionsPayload()[0];
    $second = $first;
    $second['transaction_amount']['amount'] = '999.00';
    app()->instance(Client::class, new FakeEnableBankingClient(transactions: [$first, $second]));
    expect(app(ConnectionManager::class)->syncAccount($remote)->status)->toBe(BankSyncRun::STATUS_FAILED);
    expect(Posting::query()->count())->toBe(0);
});

test('fallback matching keeps different amounts currencies dates and local accounts separate', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $remote = BankConnectionAccount::factory()->for(BankConnection::factory()->for($user)->create())->linkedTo($account)->create();
    $base = enableBankingTransactionsPayload()[0];
    unset($base['entry_reference']);
    $transactions = [$base];
    foreach ([['transaction_amount' => ['currency' => 'DKK', 'amount' => '129.00']], ['transaction_amount' => ['currency' => 'EUR', 'amount' => '128.00']], ['booking_date' => '2026-06-18'], ['credit_debit_indicator' => 'CRDT']] as $change) {
        $transactions[] = array_replace($base, $change);
    }
    app()->instance(Client::class, new FakeEnableBankingClient(transactions: $transactions));
    expect(app(ConnectionManager::class)->syncAccount($remote)->status)->toBe(BankSyncRun::STATUS_SUCCEEDED);
    expect(Posting::query()->count())->toBe(5);
    expect(app(ConnectionManager::class)->syncAccount($remote)->status)->toBe(BankSyncRun::STATUS_SUCCEEDED);
    $other = BankConnectionAccount::factory()->for(BankConnection::factory()->for($user)->create())->linkedTo(BankAccount::factory()->for($user)->create())->create();
    expect(app(ConnectionManager::class)->syncAccount($other)->status)->toBe(BankSyncRun::STATUS_SUCCEEDED);
    expect(Posting::query()->count())->toBe(10);
});

test('reference-less imports reject invalid money identity fields', function (array $changes) {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $remote = BankConnectionAccount::factory()->for(BankConnection::factory()->for($user)->create())->linkedTo($account)->create();
    $transaction = enableBankingTransactionsPayload()[0];
    unset($transaction['entry_reference']);
    $transaction = array_replace_recursive($transaction, $changes);
    app()->instance(Client::class, new FakeEnableBankingClient(transactions: [$transaction]));
    expect(app(ConnectionManager::class)->syncAccount($remote)->status)->toBe(BankSyncRun::STATUS_FAILED);
    expect(Posting::query()->count())->toBe(0);
})->with([
    'invalid amount' => [['transaction_amount' => ['amount' => 'invalid']]],
    'invalid currency' => [['transaction_amount' => ['currency' => '']]],
    'invalid direction' => [['credit_debit_indicator' => 'unknown']],
]);

test('placeholder entry references use the fallback and missing descriptions fail visibly', function () {
    $user = User::factory()->create();
    $account = BankAccount::factory()->for($user)->create();
    $remote = BankConnectionAccount::factory()->for(BankConnection::factory()->for($user)->create())->linkedTo($account)->create();
    $transaction = enableBankingTransactionsPayload()[0];
    $transaction['entry_reference'] = '*** hidden ***';
    app()->instance(Client::class, new FakeEnableBankingClient(transactions: [$transaction]));
    expect(app(ConnectionManager::class)->syncAccount($remote)->status)->toBe(BankSyncRun::STATUS_SUCCEEDED);
    expect(Posting::query()->sole()->external_posting_id)->toBeNull();
    unset($transaction['remittance_information']);
    app()->instance(Client::class, new FakeEnableBankingClient(transactions: [$transaction]));
    expect(app(ConnectionManager::class)->syncAccount($remote)->status)->toBe(BankSyncRun::STATUS_FAILED);
    expect(Posting::query()->count())->toBe(1);
});

test('reauthorization rejects ambiguous account identifiers without changing local links', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create(['state' => 'ambiguous-consent']);
    $local = BankAccount::factory()->for($user)->create();
    $first = BankConnectionAccount::factory()->for($connection)->linkedTo($local)->create();
    $second = BankConnectionAccount::factory()->for($connection)->create();
    app()->instance(Client::class, new FakeEnableBankingClient(session: enableBankingSessionPayload()));

    expect(fn () => app(ConnectionManager::class)->completeAuthorization($user, 'ambiguous-consent', 'code'))
        ->toThrow(RuntimeException::class, 'Bank account identifiers match multiple accounts.');
    expect($first->refresh()->bank_account_id)->toBe($local->id)
        ->and($second->refresh()->bank_account_id)->toBeNull()
        ->and($connection->refresh()->state)->toBe('ambiguous-consent');
});

test('reauthorization rejects matches across multiple existing connections', function () {
    $user = User::factory()->create();
    $pending = BankConnection::factory()->for($user)->create(['state' => 'ambiguous-connections']);
    foreach (range(1, 2) as $index) {
        BankConnectionAccount::factory()->for(BankConnection::factory()->for($user)->create())->create();
    }
    app()->instance(Client::class, new FakeEnableBankingClient(session: enableBankingSessionPayload()));

    expect(fn () => app(ConnectionManager::class)->completeAuthorization($user, 'ambiguous-connections', 'code'))
        ->toThrow(RuntimeException::class, 'Bank account identifiers match multiple connections.');
    expect($pending->refresh()->state)->toBe('ambiguous-connections');
});

test('reauthorization rejects account currency changes without modifying the local link', function () {
    $user = User::factory()->create();
    $connection = BankConnection::factory()->for($user)->create(['state' => 'currency-change']);
    $local = BankAccount::factory()->for($user)->create();
    $remote = BankConnectionAccount::factory()->for($connection)->linkedTo($local)->create();
    $session = enableBankingSessionPayload();
    $session['accounts'][0]['currency'] = 'EUR';
    app()->instance(Client::class, new FakeEnableBankingClient(session: $session));

    expect(fn () => app(ConnectionManager::class)->completeAuthorization($user, 'currency-change', 'code'))
        ->toThrow(RuntimeException::class, 'A reauthorized bank account changed currency.');
    expect($remote->refresh()->bank_account_id)->toBe($local->id)
        ->and($remote->currency)->toBe('DKK');
});
