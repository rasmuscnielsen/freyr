<?php

namespace App\Platform;

use App\Enums\PlatformSettingKey;
use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings';

    /**
     * Per-request memo of the raw stored rows, keyed by setting key.
     *
     * @var array<string, string|null>|null
     */
    private ?array $memo = null;

    /**
     * Read a setting, falling back to its declared default when unset.
     */
    public function get(PlatformSettingKey $key): mixed
    {
        $value = $this->stored()[$key->value] ?? null;

        if ($value === null) {
            return $key->default();
        }

        if ($key->isEncrypted()) {
            $value = Crypt::decryptString($value);
        }

        if ($key->isBoolean()) {
            return $value === '1';
        }

        return $value;
    }

    /**
     * Store a setting, encrypting it when the key calls for it. Booleans are
     * persisted as '1'/'0' and a null value removes the row entirely.
     */
    public function set(PlatformSettingKey $key, mixed $value): void
    {
        if ($value === null) {
            $this->forget($key);

            return;
        }

        if ($key->isBoolean()) {
            $value = $value ? '1' : '0';
        }

        PlatformSetting::query()->updateOrCreate(
            ['key' => $key->value],
            [
                'value' => $key->isEncrypted() ? Crypt::encryptString((string) $value) : (string) $value,
                'is_encrypted' => $key->isEncrypted(),
            ],
        );

        $this->flush();
    }

    public function forget(PlatformSettingKey $key): void
    {
        PlatformSetting::query()->whereKey($key->value)->delete();

        $this->flush();
    }

    /**
     * Whether a row is stored for the key, regardless of the fallback default.
     */
    public function isStored(PlatformSettingKey $key): bool
    {
        return array_key_exists($key->value, $this->stored());
    }

    public function registrationEnabled(): bool
    {
        return (bool) $this->get(PlatformSettingKey::RegistrationEnabled);
    }

    public function onboardingCompleted(): bool
    {
        return filled($this->get(PlatformSettingKey::OnboardingCompletedAt));
    }

    public function markOnboardingCompleted(): void
    {
        $this->set(PlatformSettingKey::OnboardingCompletedAt, now()->toIso8601String());
    }

    /**
     * Whether any user exists yet; drives the first-run onboarding wizard.
     */
    public function needsFirstUser(): bool
    {
        return ! User::query()->exists();
    }

    /**
     * Whether Enable Banking is usable, i.e. both the application id and the
     * private key resolve to a non-empty value (from the database or env).
     */
    public function enableBankingConfigured(): bool
    {
        return filled($this->get(PlatformSettingKey::EnableBankingAppId))
            && filled($this->get(PlatformSettingKey::EnableBankingPrivateKey));
    }

    /**
     * @return array<string, mixed>
     */
    public function enableBankingConfig(): array
    {
        return array_merge(config('services.enable_banking'), [
            'app_id' => $this->get(PlatformSettingKey::EnableBankingAppId),
            'private_key' => $this->get(PlatformSettingKey::EnableBankingPrivateKey),
        ]);
    }

    public function storeEnableBankingCredentials(string $appId, string $pem): void
    {
        $this->set(PlatformSettingKey::EnableBankingAppId, $appId);
        $this->set(PlatformSettingKey::EnableBankingPrivateKey, $pem);
    }

    public function forgetEnableBankingCredentials(): void
    {
        $this->forget(PlatformSettingKey::EnableBankingAppId);
        $this->forget(PlatformSettingKey::EnableBankingPrivateKey);
    }

    /**
     * The raw stored rows (still encrypted), cached forever and memoized for
     * the request.
     *
     * @return array<string, string|null>
     */
    private function stored(): array
    {
        return $this->memo ??= Cache::rememberForever(
            self::CACHE_KEY,
            fn (): array => PlatformSetting::query()->pluck('value', 'key')->all(),
        );
    }

    private function flush(): void
    {
        $this->memo = null;

        Cache::forget(self::CACHE_KEY);
    }
}
