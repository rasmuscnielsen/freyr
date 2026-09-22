<?php

use App\Enums\PlatformSettingKey;
use App\Jobs\ImportSpiirExport;
use App\Models\BankAccount;
use App\Models\Posting;
use App\Models\User;
use App\Platform\PlatformSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
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
    $response->assertRedirect(route('onboarding.registration'));
});

test('the onboarding welcome redirects to login once a user exists', function () {
    User::factory()->create();

    $this->get(route('onboarding.welcome'))->assertRedirect(route('login'));
});

test('a platform admin with incomplete onboarding is redirected to the wizard', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertRedirect(route('onboarding.registration'));
});

test('a regular user with incomplete onboarding is not redirected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk();
});

test('the registration step stores the setting', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->post(route('onboarding.registration.store'), ['enabled' => '1'])
        ->assertRedirect(route('onboarding.enable-banking'));

    expect(app(PlatformSettings::class)->registrationEnabled())->toBeTrue();
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

    app(PlatformSettings::class)->markOnboardingCompleted();

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

    app(PlatformSettings::class)->markOnboardingCompleted();

    $this->actingAs($admin)
        ->get(route('spiir-import.show', $importId))
        ->assertOk()
        ->assertJsonPath('status', 'completed')
        ->assertJsonPath('summary.user_id', $admin->id);

    expect(User::query()->count())->toBe(1)
        ->and(Posting::query()->count())->toBe(5)
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
    app(PlatformSettings::class)->markOnboardingCompleted();

    $this->actingAs($admin)
        ->get(route('spiir-import.show', 'does-not-exist'))
        ->assertNotFound();
});

test('completing onboarding marks it done and unlocks the app', function () {
    $admin = User::factory()->platformAdmin()->create();

    $this->actingAs($admin)
        ->post(route('onboarding.complete'))
        ->assertRedirect(route('dashboard'));

    expect(app(PlatformSettings::class)->onboardingCompleted())->toBeTrue();

    $this->actingAs($admin)
        ->get(route('onboarding.registration'))
        ->assertRedirect(route('dashboard'));

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertOk();
});
