<?php

use App\Enums\PlatformSettingKey;
use App\Models\PlatformSetting;
use App\Platform\PlatformSettings;
use Illuminate\Support\Facades\Crypt;

test('it stores and reads a boolean setting', function () {
    $settings = app(PlatformSettings::class);

    expect($settings->registrationEnabled())->toBeFalse();

    $settings->set(PlatformSettingKey::RegistrationEnabled, true);

    expect($settings->registrationEnabled())->toBeTrue()
        ->and(PlatformSetting::query()->find('registration.enabled')->value)->toBe('1');

    $settings->set(PlatformSettingKey::RegistrationEnabled, false);

    expect($settings->registrationEnabled())->toBeFalse();
});

test('it stores encrypted values and decrypts them on read', function () {
    $settings = app(PlatformSettings::class);

    $settings->set(PlatformSettingKey::EnableBankingPrivateKey, 'super-secret-pem');

    $row = PlatformSetting::query()->find('enable_banking.private_key');

    expect($row->is_encrypted)->toBeTrue()
        ->and($row->value)->not->toBe('super-secret-pem')
        ->and(Crypt::decryptString($row->value))->toBe('super-secret-pem')
        ->and($settings->get(PlatformSettingKey::EnableBankingPrivateKey))->toBe('super-secret-pem');
});

test('setting a null value removes the row', function () {
    $settings = app(PlatformSettings::class);

    $settings->set(PlatformSettingKey::RegistrationEnabled, true);
    $settings->set(PlatformSettingKey::RegistrationEnabled, null);

    expect(PlatformSetting::query()->find('registration.enabled'))->toBeNull()
        ->and($settings->isStored(PlatformSettingKey::RegistrationEnabled))->toBeFalse()
        ->and($settings->registrationEnabled())->toBeFalse();
});

test('enable banking falls back to env config when nothing is stored', function () {
    config()->set('services.enable_banking.app_id', 'env-app-id');
    config()->set('services.enable_banking.private_key', 'env-key');

    $settings = app(PlatformSettings::class);

    expect($settings->enableBankingConfigured())->toBeTrue()
        ->and($settings->enableBankingConfig())
        ->toMatchArray(['app_id' => 'env-app-id', 'private_key' => 'env-key']);
});

test('stored enable banking values override env config', function () {
    config()->set('services.enable_banking.app_id', 'env-app-id');
    config()->set('services.enable_banking.private_key', 'env-key');

    $settings = app(PlatformSettings::class);
    $settings->storeEnableBankingCredentials('db-app-id', 'db-key');

    expect($settings->enableBankingConfigured())->toBeTrue()
        ->and($settings->enableBankingConfig())
        ->toMatchArray(['app_id' => 'db-app-id', 'private_key' => 'db-key'])
        ->and($settings->isStored(PlatformSettingKey::EnableBankingAppId))->toBeTrue();

    $settings->forgetEnableBankingCredentials();

    expect($settings->enableBankingConfig())
        ->toMatchArray(['app_id' => 'env-app-id', 'private_key' => 'env-key']);
});

test('onboarding completion is tracked as a setting', function () {
    $settings = app(PlatformSettings::class);

    expect($settings->onboardingCompleted())->toBeFalse();

    $settings->markOnboardingCompleted();

    expect($settings->onboardingCompleted())->toBeTrue()
        ->and($settings->get(PlatformSettingKey::OnboardingCompletedAt))->not->toBeNull();
});
