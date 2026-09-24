<?php

use App\Enums\PlatformSettingKey;
use App\Models\User;
use App\Platform\PlatformSettings;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

function platformAdminWithCompletedOnboarding(): User
{
    app(PlatformSettings::class)->markOnboardingCompleted();

    return User::factory()->platformAdmin()->create();
}

function pemUpload(): UploadedFile
{
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    openssl_pkey_export($key, $pem);

    return UploadedFile::fake()->createWithContent('app.pem', $pem);
}

test('guests are redirected to login', function () {
    User::factory()->create();

    $this->get(route('platform.settings.edit'))
        ->assertRedirect(route('login'));
});

test('non-admin users are forbidden', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('platform.settings.edit'))
        ->assertForbidden();
});

test('platform admins can view the settings page with its props', function () {
    $admin = platformAdminWithCompletedOnboarding();

    $this->actingAs($admin)
        ->get(route('platform.settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/settings/index')
            ->has('registrationEnabled')
            ->has('enableBanking.configured')
            ->has('enableBanking.appId')
            ->has('enableBanking.source')
            ->has('spiir.canImport')
            ->has('spiir.hasDefaultCategories'));
});

test('the registration setting can be toggled', function () {
    $admin = platformAdminWithCompletedOnboarding();

    $this->actingAs($admin)
        ->patch(route('platform.settings.registration.update'), ['enabled' => '1'])
        ->assertRedirect();

    expect(app(PlatformSettings::class)->registrationEnabled())->toBeTrue();
});

test('enable banking credentials can be stored and removed', function () {
    $admin = platformAdminWithCompletedOnboarding();
    $settings = app(PlatformSettings::class);

    $this->actingAs($admin)
        ->put(route('platform.settings.enable-banking.update'), [
            'app_id' => 'app-123',
            'private_key' => pemUpload(),
        ])
        ->assertRedirect();

    expect($settings->enableBankingConfigured())->toBeTrue()
        ->and($settings->get(PlatformSettingKey::EnableBankingAppId))->toBe('app-123');

    $this->actingAs($admin)
        ->delete(route('platform.settings.enable-banking.destroy'))
        ->assertRedirect();

    expect($settings->isStored(PlatformSettingKey::EnableBankingAppId))->toBeFalse()
        ->and($settings->enableBankingConfigured())->toBeFalse();
});
