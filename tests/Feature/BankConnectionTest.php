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
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Queue;
use Inertia\Support\Header;
use Inertia\Testing\AssertableInertia as Assert;

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
                'account_servicer' => ['bic_fi' => 'NYKBDKKK'],
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
                'account_servicer' => ['bic_fi' => 'NYKBDKKK'],
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
            'name' => 'Nykredit Bank',
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
            'aspsp_name' => 'Nykredit Bank',
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
            'aspsp_name' => 'Nykredit Bank',
            'aspsp_country' => 'DK',
        ])
        ->assertRedirect('https://enablebanking.test/login');
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
        ->assertRedirect(route('bank-accounts.index'));

    expect($connection->refresh()->status)->toBe(BankConnection::STATUS_ACTIVE)
        ->and($connection->aspsp_name)->toBe('Nykredit Bank')
        ->and($connection->session_id_hash)->toBe(hash('sha256', '11111111-2222-4333-8444-555555555555'))
        ->and($connection->accounts)->toHaveCount(2)
        ->and($connection->accounts()->first()->details)->toBe('Everyday');
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
            'name' => 'Nykredit Nemkonto',
        ])
        ->assertRedirect();

    expect($newRemoteAccount->refresh()->status)->toBe(BankConnectionAccount::STATUS_LINKED)
        ->and($newRemoteAccount->bankAccount->user_id)->toBe($user->id)
        ->and($newRemoteAccount->bankAccount->name)->toBe('Nykredit Nemkonto');

    Queue::assertPushed(
        SyncBankConnectionAccountJob::class,
        fn (SyncBankConnectionAccountJob $job): bool => $job->bankConnectionAccount->is($newRemoteAccount),
    );
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

    expect($client->transactionDateFroms)->toBe(['2026-01-01'])
        ->and(Posting::query()->count())->toBe(1)
        ->and(Posting::query()->where('external_posting_id', 'future-enable-banking-transaction-id')->exists())->toBeFalse();

    $posting = Posting::query()->firstOrFail();

    expect($posting->amount)->toBe('-128.00')
        ->and($posting->source)->toBe(BankConnectionAccount::SOURCE_ENABLE_BANKING)
        ->and($posting->external_posting_id)->toBe('stable-enable-banking-transaction-id')
        ->and($localAccount->refresh()->last_booked_balance)->toBe('51339.66')
        ->and($remoteAccount->refresh()->last_transaction_date->toDateString())->toBe('2026-06-19');

    (new SyncBankConnectionAccountJob($remoteAccount))->handle(app(EnableBankingConnectionManager::class));

    $latestRun = BankSyncRun::query()->latest('id')->firstOrFail();

    expect($client->transactionDateFroms)->toBe(['2026-01-01', '2026-06-18'])
        ->and(Posting::query()->count())->toBe(1)
        ->and($latestRun->duplicate_postings_count)->toBe(1);
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
        ->assertRedirect(route('bank-accounts.index'));

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

test('sync leaves reconciliation null when postings carry no running balance', function () {
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
        balances: enableBankingBalancesPayload(),
        transactions: $transactions,
    );

    app()->instance(EnableBankingClient::class, $client);

    (new SyncBankConnectionAccountJob($remoteAccount))->handle(app(EnableBankingConnectionManager::class));

    $run = BankSyncRun::query()->latest('id')->firstOrFail();

    expect($run->status)->toBe(BankSyncRun::STATUS_SUCCEEDED)
        ->and($run->expected_balance)->toBe('51339.66')
        ->and($run->computed_balance)->toBeNull()
        ->and($run->balance_matches)->toBeNull()
        ->and($run->hasBalanceMismatch())->toBeFalse()
        ->and($run->balanceDelta())->toBeNull();
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

test('removing a connection is authorized to the owner', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $connection = BankConnection::factory()->for($owner)->create();

    $this->actingAs($intruder)
        ->delete(route('bank-connections.destroy', $connection))
        ->assertForbidden();

    expect(BankConnection::query()->count())->toBe(1);
});

class FakeEnableBankingClient extends EnableBankingClient
{
    /**
     * @var list<string>
     */
    public array $transactionDateFroms = [];

    /**
     * @param  array<string, mixed>  $session
     * @param  list<array<string, mixed>>  $balances
     * @param  list<array<string, mixed>>  $transactions
     */
    public function __construct(
        private readonly array $session = [],
        private readonly array $balances = [],
        private readonly array $transactions = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function startAuthorization(string $redirectUrl, string $state, ?string $aspspName = null, ?string $aspspCountry = null): array
    {
        return [
            'authorization_id' => '2c829ff8-ed4e-4fec-a476-19fded89011a',
            'url' => 'https://enablebanking.test/login',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function aspsps(?string $country = null, ?string $psuType = null, ?string $service = 'AIS'): array
    {
        return [
            'aspsps' => [
                ['name' => 'Nykredit Bank', 'country' => 'DK'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function createSession(string $code): array
    {
        return $this->session;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function balances(string $accountUid): array
    {
        return $this->balances;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function transactions(string $accountUid, CarbonInterface $dateFrom): array
    {
        $this->transactionDateFroms[] = $dateFrom->toDateString();

        return $this->transactions;
    }
}
