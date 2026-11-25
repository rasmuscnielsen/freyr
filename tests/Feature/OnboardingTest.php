<?php

use App\Enums\PlatformSettingKey;
use App\Jobs\GeneratePostingEmbeddings;
use App\Jobs\ImportSpiirExport;
use App\Models\BankAccount;
use App\Models\Posting;
use App\Models\User;
use App\Services\Spiir\Importer;
use App\Support\PlatformSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function fixtureUpload(string $name): UploadedFile
{
    return UploadedFile::fake()->createWithContent(
        $name,
        file_get_contents(base_path("tests/Fixtures/spiir-export/{$name}")),
    );
}

function generatePem(): string
{
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $pem);

    return $pem;
}

test('a fresh install redirects the login page to the onboarding welcome', function () {
    $this->get(route('login'))->assertRedirect(route('onboarding.welcome'));
});

test('only configured proxies can forward HTTPS for onboarding redirects', function (string $proxies, string $remoteAddress, string $forwardedScheme, string $scheme) {
    config(['trustedproxy.proxies' => $proxies]);

    $this->withServerVariables(['REMOTE_ADDR' => $remoteAddress])
        ->withHeader('X-Forwarded-Proto', $forwardedScheme)
        ->get('http://freyr.test/login')
        ->assertRedirect("{$scheme}://freyr.test/onboarding");
})->with([
    'IPv4 in a comma-separated list' => ['::1, 127.0.0.1', '127.0.0.1', 'https', 'https'],
    'IPv6' => ['127.0.0.1,::1', '::1', 'https', 'https'],
    'no trusted proxies' => ['', '127.0.0.1', 'https', 'http'],
    'untrusted remote' => ['127.0.0.1,::1', '192.0.2.1', 'https', 'http'],
    'inside a CIDR range' => ['192.0.2.0/24', '192.0.2.254', 'https', 'https'],
    'outside a CIDR range' => ['192.0.2.0/24', '192.0.3.1', 'https', 'http'],
    'HTTP through a trusted proxy' => ['127.0.0.1', '127.0.0.1', 'http', 'http'],
]);

test('the onboarding welcome renders on a fresh install', function () {
    $this->get(route('onboarding.welcome'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('onboarding/welcome')
            ->has('passwordRules'));
});

test('creating the first account makes a verified platform admin and signs in', function () {
    $response = $this->post(route('onboarding.account.store'), [
        'name' => 'Jane Admin',
        'email' => 'jane@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::query()->sole();

    expect($user->is_platform_admin)->toBeTrue()
        ->and($user->email_verified_at)->not->toBeNull();

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('onboarding.region'));
});

test('the onboarding welcome redirects to login once a user exists', function () {
    User::factory()->create();

    $this->get(route('onboarding.welcome'))->assertRedirect(route('login'));
});

test('a platform admin with incomplete onboarding is redirected to the wizard', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('onboarding.enable-banking'));
});

test('a regular user with incomplete onboarding is not redirected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('the enable banking step stores encrypted credentials', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->post(route('onboarding.enable-banking.store'), [
            'app_id' => 'app-123',
            'private_key' => UploadedFile::fake()->createWithContent('app.pem', generatePem()),
        ])
        ->assertRedirect(route('onboarding.spiir'));

    $settings = app(PlatformSettings::class);
    expect($settings->enableBankingConfigured())->toBeTrue()
        ->and($settings->get(PlatformSettingKey::EnableBankingAppId))->toBe('app-123');
});

test('the enable banking step rejects an invalid private key', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->from(route('onboarding.enable-banking'))
        ->post(route('onboarding.enable-banking.store'), [
            'app_id' => 'app-123',
            'private_key' => UploadedFile::fake()->createWithContent('app.pem', 'not a pem'),
        ])
        ->assertSessionHasErrors('private_key');
});

test('the enable banking step can be skipped', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->post(route('onboarding.enable-banking.skip'))
        ->assertRedirect(route('onboarding.spiir'));

    expect(app(PlatformSettings::class)->enableBankingConfigured())->toBeFalse();
});

test('the spiir step dispatches the import job and exposes its status', function () {
    Queue::fake();

    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->post(route('onboarding.spiir.store'), [
            'categories' => fixtureUpload('Categories.json'),
            'user' => fixtureUpload('User.csv'),
            'accounts' => fixtureUpload('Account.csv'),
            'postings' => fixtureUpload('Posting.csv'),
        ])
        ->assertRedirect();

    Queue::assertPushed(ImportSpiirExport::class);

    $importId = session('spiir_import_id');
    expect($importId)->not->toBeNull();

    $this->actingAs($admin)
        ->get(route('spiir-import.show', $importId))
        ->assertOk()
        ->assertJsonPath('status', 'pending');
});

test('the spiir step imports the export for the signed-in user when run synchronously', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->post(route('onboarding.spiir.store'), [
            'categories' => fixtureUpload('Categories.json'),
            'user' => fixtureUpload('User.csv'),
            'accounts' => fixtureUpload('Account.csv'),
            'postings' => fixtureUpload('Posting.csv'),
        ])
        ->assertRedirect();

    $importId = session('spiir_import_id');

    $this->actingAs($admin)
        ->get(route('spiir-import.show', $importId))
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('summary.user_id', $admin->id);

    expect(User::query()->count())->toBe(1)
        ->and(Posting::query()->count())->toBe(7)
        ->and(BankAccount::query()->count())->toBe(2);
});

test('the spiir import is rejected once postings exist', function () {
    $admin = User::factory()->platformAdmin()->create();
    Posting::factory()->create();

    $this->actingAs($admin)
        ->from(route('onboarding.spiir'))
        ->post(route('onboarding.spiir.store'), [
            'categories' => fixtureUpload('Categories.json'),
            'user' => fixtureUpload('User.csv'),
            'accounts' => fixtureUpload('Account.csv'),
            'postings' => fixtureUpload('Posting.csv'),
        ])
        ->assertSessionHasErrors('postings');
});

test('the status endpoint 404s for an unknown import', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->get(route('spiir-import.show', 'does-not-exist'))
        ->assertNotFound();
});

test('spiir import status hides stored errors and belongs only to its owner', function () {
    $owner = User::factory()->platformAdmin()->create();
    $otherAdmin = User::factory()->platformAdmin()->create();
    $importId = 'import-with-error';
    Cache::put(ImportSpiirExport::cacheKey($importId), [
        'status' => 'failed',
        'user_id' => $owner->id,
        'error' => 'SQLSTATE[23503] secret-import-data',
    ]);

    $this->actingAs($owner)
        ->get(route('spiir-import.show', $importId))
        ->assertOk()
        ->assertJsonPath('error', 'The import could not be completed.')
        ->assertDontSee('secret-import-data');

    $this->actingAs($owner)
        ->withSession(['spiir_import_id' => $importId])
        ->get(route('onboarding.spiir'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('import.error', 'The import could not be completed.'))
        ->assertDontSee('secret-import-data');

    $this->actingAs($otherAdmin)
        ->get(route('spiir-import.show', $importId))
        ->assertNotFound();
});

test('failed spiir jobs cache only a safe error message', function () {
    $admin = User::factory()->platformAdmin()->create();
    Storage::fake('local');
    $job = new ImportSpiirExport('missing-import', $admin->id);

    $job->handle(app(Importer::class));

    $status = Cache::get(ImportSpiirExport::cacheKey('missing-import'));
    expect($status['status'])->toBe('failed')
        ->and($status['error'])->toBe(ImportSpiirExport::FAILED_MESSAGE);
});

test('the similarity step stores the key, enables the feature and completes onboarding', function () {
    Queue::fake();
    fakeOpenAiEmbeddings();

    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->post(route('onboarding.similarity.store'), [
            'api_key' => 'sk-test-1234567890',
            'enabled' => '1',
            'auto_categorize' => '1',
        ])
        ->assertRedirect(route('dashboard'));

    $settings = app(PlatformSettings::class);
    expect($settings->openAiApiKey())->toBe('sk-test-1234567890')
        ->and($settings->similarityEnabled())->toBeTrue()
        ->and($settings->similarityAutoCategorize())->toBeTrue()
        ->and($settings->similarityAvailable())->toBeTrue()
        ->and($settings->onboardingCompleted())->toBeTrue();

    Queue::assertPushed(GeneratePostingEmbeddings::class);
});

test('the similarity step can be skipped and still completes onboarding', function () {
    Queue::fake();

    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->post(route('onboarding.similarity.store'), [
            'enabled' => '0',
            'auto_categorize' => '0',
        ])
        ->assertRedirect(route('dashboard'));

    $settings = app(PlatformSettings::class);
    expect($settings->isStored(PlatformSettingKey::OpenAiApiKey))->toBeFalse()
        ->and($settings->similarityEnabled())->toBeFalse()
        ->and($settings->similarityAvailable())->toBeFalse()
        ->and($settings->onboardingCompleted())->toBeTrue();

    Queue::assertNotPushed(GeneratePostingEmbeddings::class);
});

test('the similarity step keeps onboarding open when the key does not work', function () {
    Queue::fake();
    config()->set('ai.providers.openai.key', null);
    fakeOpenAiEmbeddings(401, 'invalid_api_key', 'Incorrect API key provided.');

    $this->actingAs(User::factory()->platformAdmin()->create())
        ->from(route('onboarding.similarity'))
        ->post(route('onboarding.similarity.store'), [
            'api_key' => 'sk-bad-key-12345',
            'enabled' => '1',
            'auto_categorize' => '0',
        ])
        ->assertRedirect(route('onboarding.similarity'))
        ->assertSessionHasErrors(['api_key' => "We couldn't verify this key. Please check the key and try again. OpenAI error: Incorrect API key provided."]);

    $settings = app(PlatformSettings::class);
    expect($settings->openAiConfigured())->toBeFalse()
        ->and($settings->similarityEnabled())->toBeFalse()
        ->and($settings->onboardingCompleted())->toBeFalse();

    Queue::assertNotPushed(GeneratePostingEmbeddings::class);
});

test('enabling similarity in onboarding requires an api key', function () {
    Queue::fake();
    config()->set('ai.providers.openai.key', null);

    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->from(route('onboarding.similarity'))
        ->post(route('onboarding.similarity.store'), [
            'enabled' => '1',
            'auto_categorize' => '0',
        ])
        ->assertSessionHasErrors('api_key');

    expect(app(PlatformSettings::class)->onboardingCompleted())->toBeFalse();
});

test('completing onboarding marks it done and unlocks the app', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->post(route('onboarding.complete'))
        ->assertRedirect(route('dashboard'));

    expect(app(PlatformSettings::class)->onboardingCompleted())->toBeTrue();

    $this->actingAs($admin)
        ->get(route('onboarding.similarity'))
        ->assertRedirect(route('dashboard'));

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk();
});
