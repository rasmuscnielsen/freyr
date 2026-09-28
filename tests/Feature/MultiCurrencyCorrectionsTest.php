<?php

use App\Finance\AccountBalanceRecalculator;
use App\Finance\BalanceChainInspector;
use App\Finance\EnableBanking\EnableBankingConnectionManager;
use App\Finance\Fx\ExchangeRateStore;
use App\Finance\Fx\FrankfurterClient;
use App\Jobs\ConvertReportingAmountsJob;
use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\BankSyncRun;
use App\Models\Posting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

function correctionPosting(User $user, string $date, array $overrides = []): Posting
{
    $account = BankAccount::factory()->for($user)->create(['currency' => 'EUR', 'current_balance' => null]);

    return Posting::factory()->forAccount($account)->create([
        'date' => $date,
        'original_amount' => '-10.00',
        'original_currency' => 'EUR',
        'amount' => null,
        'currency' => 'USD',
        'balance' => null,
        'categorized_subcategory_id' => null,
        ...$overrides,
    ]);
}

function runCorrectionConversion(User $user, ?ExchangeRateStore $store = null): void
{
    (new ConvertReportingAmountsJob($user->id))->handle(
        app(FrankfurterClient::class),
        $store ?? app(ExchangeRateStore::class),
    );
}

test('a published weekday rate replaces an unverified older fallback', function () {
    Http::fake(['api.frankfurter.dev/*' => Http::response([
        ['date' => '2026-01-12', 'base' => 'EUR', 'quote' => 'USD', 'rate' => '1.20'],
    ])]);
    $user = User::factory()->create(['reporting_currency' => 'USD']);
    $posting = correctionPosting($user, '2026-01-12');
    app(ExchangeRateStore::class)->storeRates([
        ['date' => '2026-01-09', 'base' => 'EUR', 'quote' => 'USD', 'rate' => '1.10'],
    ]);

    runCorrectionConversion($user);

    expect($posting->refresh()->amount)->toBe('-12.00');
});

test('a weekend fetches the missing Friday instead of using cached Thursday', function () {
    Http::fake(['api.frankfurter.dev/*' => Http::response([
        ['date' => '2026-01-09', 'base' => 'EUR', 'quote' => 'USD', 'rate' => '1.20'],
    ])]);
    $user = User::factory()->create(['reporting_currency' => 'USD']);
    $posting = correctionPosting($user, '2026-01-11');
    app(ExchangeRateStore::class)->storeRates([
        ['date' => '2026-01-08', 'base' => 'EUR', 'quote' => 'USD', 'rate' => '1.10'],
    ]);

    runCorrectionConversion($user);

    expect($posting->refresh()->amount)->toBe('-12.00');
});

test('known-missing backlog rows do not starve a newer resolvable posting', function () {
    Http::fake(['api.frankfurter.dev/*' => Http::response([
        ['date' => '2026-01-09', 'base' => 'EUR', 'quote' => 'USD', 'rate' => '1.20'],
    ])]);
    $user = User::factory()->create(['reporting_currency' => 'USD']);
    $account = BankAccount::factory()->for($user)->create(['currency' => 'EUR', 'current_balance' => null]);
    $oldDate = CarbonImmutable::parse('2020-01-01');
    app(ExchangeRateStore::class)->markMissing('EUR', 'USD', $oldDate);
    $now = now();
    $row = [
        'user_id' => $user->id,
        'bank_account_id' => $account->id,
        'date' => $oldDate->toDateString(),
        'description' => 'Synthetic unresolved posting',
        'amount' => null,
        'original_amount' => '-1.00',
        'currency' => 'USD',
        'original_currency' => 'EUR',
        'created_at' => $now,
        'updated_at' => $now,
    ];

    foreach (array_chunk(array_fill(0, 2001, $row), 500) as $rows) {
        DB::table('postings')->insert($rows);
    }

    $posting = Posting::factory()->forAccount($account)->create([
        'date' => '2026-01-09',
        'amount' => null,
        'currency' => 'USD',
        'original_amount' => '-10.00',
        'original_currency' => 'EUR',
    ]);

    runCorrectionConversion($user);

    expect($posting->refresh()->amount)->toBe('-12.00');
});

test('unrequested historical years are not negative cached', function () {
    Http::fake(['api.frankfurter.dev/*' => Http::response([])]);
    $user = User::factory()->create(['reporting_currency' => 'USD']);
    correctionPosting($user, '2022-01-10');
    correctionPosting($user, '2026-01-09');

    runCorrectionConversion($user);

    expect(app(ExchangeRateStore::class)->isKnownMissing('EUR', 'USD', CarbonImmutable::parse('2026-01-09')))->toBeFalse();
});

test('invalid successful rate responses leave money unresolved and start cooldown', function (mixed $payload) {
    Http::fake(['api.frankfurter.dev/*' => Http::response($payload)]);
    $user = User::factory()->create(['reporting_currency' => 'USD']);
    $posting = correctionPosting($user, '2026-01-09');

    runCorrectionConversion($user);

    expect($posting->refresh()->amount)->toBeNull()
        ->and(app(ExchangeRateStore::class)->inCooldown())->toBeTrue();
})->with([
    'negative rate' => [[['date' => '2026-01-09', 'base' => 'EUR', 'quote' => 'USD', 'rate' => '-1.20']]],
    'bad date' => [[['date' => 'not-a-date', 'base' => 'EUR', 'quote' => 'USD', 'rate' => '1.20']]],
    'wrong pair' => [[['date' => '2026-01-09', 'base' => 'GBP', 'quote' => 'USD', 'rate' => '1.20']]],
    'malformed top level' => [['rate' => '1.20']],
]);

test('conversion does not write over a concurrent monetary edit', function () {
    $user = User::factory()->create(['reporting_currency' => 'USD']);
    $posting = correctionPosting($user, '2026-01-09');
    app(ExchangeRateStore::class)->storeRates([
        ['date' => '2026-01-09', 'base' => 'EUR', 'quote' => 'USD', 'rate' => '1.20'],
    ]);
    $store = new class($posting->id) extends ExchangeRateStore
    {
        public function __construct(private int $postingId) {}

        public function historicalRateFor(string $base, string $quote, CarbonImmutable $date): ?array
        {
            $rate = parent::historicalRateFor($base, $quote, $date);
            Posting::query()->whereKey($this->postingId)->update(['original_amount' => '-20.00']);

            return $rate;
        }
    };

    runCorrectionConversion($user, $store);

    expect($posting->refresh()->amount)->toBeNull();
});

test('unknown account movements stop balance propagation and current balance calculation', function () {
    $user = User::factory()->create(['reporting_currency' => 'USD']);
    $first = correctionPosting($user, '2026-01-09', ['balance' => '90.00']);
    Posting::factory()->forAccount($first->bankAccount)->create([
        'date' => '2026-01-10',
        'original_amount' => null,
        'original_currency' => null,
        'balance' => null,
        'amount' => null,
        'currency' => 'USD',
    ]);

    expect(app(BalanceChainInspector::class)->inspect($first->bankAccount)['closing_balance'])->toBeNull();

    $first->bankAccount->bankConnectionAccounts()->delete();
    app(AccountBalanceRecalculator::class)->recalculate($first->bankAccount);

    expect($first->bankAccount->refresh()->current_balance)->toBeNull();
});

test('an unknown same-day tail stays unknown until a later checkpoint', function () {
    $user = User::factory()->create(['reporting_currency' => 'USD']);
    $first = correctionPosting($user, '2026-01-09', ['balance' => '90.00']);
    Posting::factory()->forAccount($first->bankAccount)->create([
        'date' => '2026-01-09',
        'original_amount' => null,
        'original_currency' => null,
        'balance' => null,
        'amount' => null,
        'currency' => 'USD',
    ]);

    expect(app(BalanceChainInspector::class)->inspect($first->bankAccount)['closing_balance'])->toBeNull();

    Posting::factory()->forAccount($first->bankAccount)->create([
        'date' => '2026-01-10',
        'original_amount' => '-5.00',
        'original_currency' => 'EUR',
        'balance' => '85.00',
        'amount' => null,
        'currency' => 'USD',
    ]);

    expect(app(BalanceChainInspector::class)->inspect($first->bankAccount)['closing_balance'])->toBe('85.00');
});

test('completed setup and imported history both lock reporting currency', function () {
    $completed = User::factory()->create(['reporting_currency' => 'USD']);
    $imported = User::factory()->platformAdmin()->withoutRegionSetup()->create(['reporting_currency' => 'USD']);
    correctionPosting($imported, '2026-01-09', ['amount' => '-12.00']);

    $this->actingAs($completed)->post(route('region-setup.store'), [
        'country_code' => 'US', 'timezone' => 'America/New_York', 'reporting_currency' => 'EUR',
    ]);
    $this->actingAs($imported)->post(route('onboarding.region.store'), [
        'country_code' => 'US', 'timezone' => 'America/New_York', 'reporting_currency' => 'EUR',
    ]);

    expect($completed->refresh()->reporting_currency)->toBe('USD')
        ->and($imported->refresh()->reporting_currency)->toBe('USD');
});

test('corrected bank movement invalidates only a changed conversion', function () {
    $user = User::factory()->create(['reporting_currency' => 'USD']);
    $account = BankAccount::factory()->for($user)->create(['currency' => 'EUR']);
    $connection = BankConnection::factory()->for($user)->create();
    $remote = BankConnectionAccount::factory()->linkedTo($account)->create([
        'bank_connection_id' => $connection->id, 'currency' => 'EUR',
    ]);
    $method = new ReflectionMethod(app(EnableBankingConnectionManager::class), 'importTransactions');
    $transaction = [
        'entry_reference' => 'synthetic-entry', 'booking_date' => '2026-01-09',
        'transaction_amount' => ['amount' => '10.00', 'currency' => 'EUR'],
        'credit_debit_indicator' => 'DBIT', 'remittance_information' => ['Synthetic merchant'],
    ];
    $method->invoke(app(EnableBankingConnectionManager::class), $remote, [$transaction]);
    $posting = $account->postings()->sole();
    $posting->update(['amount' => '-12.00']);

    $method->invoke(app(EnableBankingConnectionManager::class), $remote, [$transaction]);
    expect($posting->refresh()->amount)->toBe('-12.00');

    $transaction['transaction_amount']['amount'] = '20.00';
    $method->invoke(app(EnableBankingConnectionManager::class), $remote, [$transaction]);
    expect($posting->refresh()->amount)->toBeNull();
});

test('valuation fetches rates without postings and counts unknown balances', function () {
    Http::fake(['api.frankfurter.dev/*' => Http::response([])]);
    $user = User::factory()->create(['reporting_currency' => 'USD']);
    BankAccount::factory()->for($user)->create(['currency' => 'EUR', 'current_balance' => '100.00', 'is_active' => true]);
    BankAccount::factory()->for($user)->create(['currency' => 'USD', 'current_balance' => null, 'is_active' => true]);

    runCorrectionConversion($user);
    Http::assertSentCount(1);

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(
        fn (Assert $page) => $page->where('balance.missing_accounts', 2),
    );
});

test('failed requests count toward the provider request budget', function () {
    Http::fake(['api.frankfurter.dev/*' => Http::response(['message' => 'unsupported'], 422)]);
    $user = User::factory()->create(['reporting_currency' => 'USD']);

    foreach (['EUR', 'GBP', 'CAD', 'AUD'] as $currency) {
        correctionPosting($user, '2026-01-09', ['original_currency' => $currency]);
    }

    runCorrectionConversion($user);

    Http::assertSentCount(3);
});

test('dashboard coverage uses eligible overview postings in the completed period', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-10'));
    $user = User::factory()->create(['reporting_currency' => 'USD']);
    correctionPosting($user, '2026-02-09', ['original_amount' => null, 'original_currency' => null]);
    correctionPosting($user, '2026-03-09');

    $this->actingAs($user)->get(route('dashboard'))->assertInertia(
        fn (Assert $page) => $page->where('summary.coverage.missing', 1),
    );
});

test('timezone is independent of country and derived syncs expose their basis', function () {
    $user = User::factory()->withoutRegionSetup()->create();
    $this->actingAs($user)->post(route('region-setup.store'), [
        'country_code' => 'GB', 'timezone' => 'America/New_York', 'reporting_currency' => 'USD',
    ])->assertSessionHasNoErrors();

    $account = BankAccount::factory()->for($user)->create(['currency' => 'USD']);
    $connection = BankConnection::factory()->for($user)->create();
    $remote = BankConnectionAccount::factory()->linkedTo($account)->create([
        'bank_connection_id' => $connection->id, 'currency' => 'USD',
    ]);
    BankSyncRun::factory()->create([
        'user_id' => $user->id,
        'bank_connection_id' => $connection->id,
        'bank_connection_account_id' => $remote->id,
        'status' => BankSyncRun::STATUS_SUCCEEDED,
        'balance_matches' => true,
        'balance_match_basis' => 'derived',
    ]);

    $this->actingAs($user)->get(route('bank-accounts.show', $account))->assertInertia(
        fn (Assert $page) => $page->where('syncRuns.0.balance_match_basis', 'derived'),
    );
});
