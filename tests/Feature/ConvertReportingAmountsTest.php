<?php

use App\Finance\Fx\ExchangeRateStore;
use App\Finance\Fx\FrankfurterClient;
use App\Jobs\ConvertReportingAmountsJob;
use App\Models\BankAccount;
use App\Models\Posting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

function runConversion(User $user): void
{
    (new ConvertReportingAmountsJob($user->id))->handle(
        app(FrankfurterClient::class),
        app(ExchangeRateStore::class),
    );
}

test('same-currency postings are copied to the reporting pair without provider calls', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create(['reporting_currency' => 'DKK']);
    $account = BankAccount::factory()->for($user)->create(['currency' => 'DKK']);
    $posting = Posting::factory()->forAccount($account)->create([
        'date' => '2026-01-10',
        'amount' => null,
        'currency' => 'DKK',
        'original_amount' => '-250.75',
        'original_currency' => 'DKK',
    ]);

    runConversion($user);

    $posting->refresh();

    expect((float) $posting->amount)->toBe(-250.75)
        ->and($posting->currency)->toBe('DKK')
        ->and($posting->original_currency)->toBe('DKK');
});

test('a foreign posting converts at the stored historical rate', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create(['reporting_currency' => 'DKK']);
    $account = BankAccount::factory()->for($user)->create(['currency' => 'EUR', 'current_balance' => null]);
    $posting = Posting::factory()->forAccount($account)->create([
        'date' => '2026-01-09',
        'amount' => null,
        'currency' => 'DKK',
        'original_amount' => '-100.00',
        'original_currency' => 'EUR',
    ]);

    app(ExchangeRateStore::class)->storeRates([[
        'date' => '2026-01-09',
        'base' => 'EUR',
        'quote' => 'DKK',
        'rate' => '7.4565',
    ]]);

    runConversion($user);

    expect((float) $posting->refresh()->amount)->toBe(-745.65)
        ->and($posting->currency)->toBe('DKK')
        ->and($posting->original_currency)->toBe('EUR');
});

test('a weekend posting converts at the preceding publication rate', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create(['reporting_currency' => 'DKK']);
    $account = BankAccount::factory()->for($user)->create(['currency' => 'EUR', 'current_balance' => null]);
    // Sunday 2026-01-11 has no ECB publication; Friday's rate applies.
    $posting = Posting::factory()->forAccount($account)->create([
        'date' => '2026-01-11',
        'amount' => null,
        'currency' => 'DKK',
        'original_amount' => '10.00',
        'original_currency' => 'EUR',
    ]);

    app(ExchangeRateStore::class)->storeRates([[
        'date' => '2026-01-09',
        'base' => 'EUR',
        'quote' => 'DKK',
        'rate' => '7.5000',
    ]]);

    runConversion($user);

    expect((float) $posting->refresh()->amount)->toBe(75.00);
});

test('a posting with no provider coverage stays pending', function () {
    Http::preventStrayRequests();
    Http::fake(['https://api.frankfurter.dev/*' => Http::response([])]);

    $user = User::factory()->create(['reporting_currency' => 'DKK']);
    $account = BankAccount::factory()->for($user)->create(['currency' => 'EUR', 'current_balance' => null]);
    $posting = Posting::factory()->forAccount($account)->create([
        'date' => '1990-01-01',
        'amount' => null,
        'currency' => 'DKK',
        'original_amount' => '-5.00',
        'original_currency' => 'EUR',
    ]);

    runConversion($user);

    expect($posting->refresh()->amount)->toBeNull()
        ->and($posting->currency)->toBe('DKK')
        ->and((float) $posting->original_amount)->toBe(-5.00);
});

test('the job fetches missing rates from the provider once', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.frankfurter.dev/*' => Http::response([
            ['date' => '2026-01-09', 'base' => 'EUR', 'quote' => 'DKK', 'rate' => '7.45'],
        ]),
    ]);

    $user = User::factory()->create(['reporting_currency' => 'DKK']);
    $account = BankAccount::factory()->for($user)->create(['currency' => 'EUR', 'current_balance' => null]);
    $posting = Posting::factory()->forAccount($account)->create([
        'date' => '2026-01-09',
        'amount' => null,
        'currency' => 'DKK',
        'original_amount' => '-10.00',
        'original_currency' => 'EUR',
    ]);

    runConversion($user);

    expect((float) $posting->refresh()->amount)->toBe(-74.50);

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.frankfurter.dev/v2/providers/ecb/rates'));
});

test('a rate-limited provider starts the shared cooldown and leaves postings pending', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.frankfurter.dev/*' => Http::response('rate limited', 429, ['Retry-After' => '60']),
    ]);

    $user = User::factory()->create(['reporting_currency' => 'DKK']);
    $account = BankAccount::factory()->for($user)->create(['currency' => 'EUR', 'current_balance' => null]);
    $posting = Posting::factory()->forAccount($account)->create([
        'date' => '2026-01-09',
        'amount' => null,
        'currency' => 'DKK',
        'original_amount' => '-10.00',
        'original_currency' => 'EUR',
    ]);

    runConversion($user);

    expect($posting->refresh()->amount)->toBeNull()
        ->and(app(ExchangeRateStore::class)->inCooldown())->toBeTrue();

    // During cooldown no further requests are attempted.
    Http::assertSentCount(1);
    runConversion($user);
    Http::assertSentCount(1);
});

test('an unsupported currency is marked missing instead of retried', function () {
    Http::preventStrayRequests();
    Http::fake([
        'https://api.frankfurter.dev/*' => Http::response(['status' => 422, 'message' => 'invalid base'], 422),
    ]);

    $user = User::factory()->create(['reporting_currency' => 'DKK']);
    $account = BankAccount::factory()->for($user)->create(['currency' => 'XXX', 'current_balance' => null]);
    $posting = Posting::factory()->forAccount($account)->create([
        'date' => '2026-01-09',
        'amount' => null,
        'currency' => 'DKK',
        'original_amount' => '-10.00',
        'original_currency' => 'XXX',
    ]);

    runConversion($user);
    runConversion($user);

    expect($posting->refresh()->amount)->toBeNull();
    Http::assertSentCount(1);
});

test('an already converted posting is never overwritten', function () {
    Http::preventStrayRequests();

    $user = User::factory()->create(['reporting_currency' => 'DKK']);
    $account = BankAccount::factory()->for($user)->create(['currency' => 'EUR', 'current_balance' => null]);
    $posting = Posting::factory()->forAccount($account)->create([
        'date' => '2026-01-09',
        'amount' => '-74.50',
        'currency' => 'DKK',
        'original_amount' => '-10.00',
        'original_currency' => 'EUR',
    ]);

    runConversion($user);

    expect((float) $posting->refresh()->amount)->toBe(-74.50);
});

test('the retry endpoint dispatches the conversion job for the owner', function () {
    Queue::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('postings.retry-conversions'))
        ->assertRedirect();

    Queue::assertPushed(ConvertReportingAmountsJob::class, fn (ConvertReportingAmountsJob $job): bool => $job->userId === $user->id);
});
