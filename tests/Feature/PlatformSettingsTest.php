<?php

use App\Enums\PlatformSettingKey;
use App\Models\PlatformSetting;
use App\Support\PlatformSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;

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

test('the openai key is stored encrypted and falls back to env config', function () {
    config()->set('ai.providers.openai.key', 'env-openai-key');

    $settings = app(PlatformSettings::class);

    expect($settings->openAiApiKey())->toBe('env-openai-key')
        ->and($settings->openAiConfigured())->toBeTrue();

    $settings->storeOpenAiApiKey('sk-stored-key');

    $row = PlatformSetting::query()->find('openai.api_key');

    expect($row->is_encrypted)->toBeTrue()
        ->and(Crypt::decryptString($row->value))->toBe('sk-stored-key')
        ->and($settings->openAiApiKey())->toBe('sk-stored-key');
});

test('forgetting the openai key also disables similarity settings', function () {
    $settings = app(PlatformSettings::class);

    $settings->storeOpenAiApiKey('sk-stored-key');
    $settings->set(PlatformSettingKey::SimilarityEnabled, true);
    $settings->set(PlatformSettingKey::SimilarityAutoCategorize, true);

    $settings->forgetOpenAiApiKey();

    expect($settings->isStored(PlatformSettingKey::OpenAiApiKey))->toBeFalse()
        ->and($settings->similarityEnabled())->toBeFalse()
        ->and($settings->similarityAutoCategorize())->toBeFalse();
});

test('similarity availability needs both the enabled flag and a resolvable key', function () {
    config()->set('ai.providers.openai.key', null);

    $settings = app(PlatformSettings::class);

    expect($settings->similarityAvailable())->toBeFalse();

    $settings->set(PlatformSettingKey::SimilarityEnabled, true);
    expect($settings->similarityAvailable())->toBeFalse();

    $settings->storeOpenAiApiKey('sk-stored-key');
    expect($settings->similarityAvailable())->toBeTrue();

    $settings->set(PlatformSettingKey::SimilarityEnabled, false);
    expect($settings->similarityAvailable())->toBeFalse()
        ->and($settings->openAiConfigured())->toBeTrue();
});

test('the similarity lookback validates stored values and maps to a date', function () {
    $settings = app(PlatformSettings::class);

    expect($settings->similaritySince())->toBe('all')
        ->and($settings->similaritySinceDate())->toBeNull();

    $settings->set(PlatformSettingKey::SimilaritySince, 'nonsense');
    expect($settings->similaritySince())->toBe('all');

    $settings->set(PlatformSettingKey::SimilaritySince, '1y');
    expect($settings->similaritySince())->toBe('1y')
        ->and($settings->similaritySinceDate())->toBeInstanceOf(CarbonImmutable::class)
        ->and($settings->similaritySinceDate()->isSameDay(now()->subYear()))->toBeTrue();
});
