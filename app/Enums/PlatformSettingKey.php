<?php

namespace App\Enums;

enum PlatformSettingKey: string
{
    case RegistrationEnabled = 'registration.enabled';

    case OnboardingCompletedAt = 'onboarding.completed_at';

    case EnableBankingAppId = 'enable_banking.app_id';

    case EnableBankingPrivateKey = 'enable_banking.private_key';

    case OpenAiApiKey = 'openai.api_key';

    case SimilarityEnabled = 'similarity.enabled';

    case SimilaritySince = 'similarity.since';

    case SimilarityAutoCategorize = 'similarity.auto_categorize';

    /**
     * Whether the stored value should be encrypted at rest.
     */
    public function isEncrypted(): bool
    {
        return match ($this) {
            self::EnableBankingAppId, self::EnableBankingPrivateKey, self::OpenAiApiKey => true,
            default => false,
        };
    }

    /**
     * Whether the value is stored as a '1'/'0' boolean flag.
     */
    public function isBoolean(): bool
    {
        return match ($this) {
            self::RegistrationEnabled, self::SimilarityEnabled, self::SimilarityAutoCategorize => true,
            default => false,
        };
    }

    /**
     * The value returned when no row is stored. The Enable Banking keys fall
     * back to the environment configuration so env-based setups keep working.
     */
    public function default(): mixed
    {
        return match ($this) {
            self::RegistrationEnabled => false,
            self::EnableBankingAppId => config('services.enable_banking.app_id'),
            self::EnableBankingPrivateKey => config('services.enable_banking.private_key'),
            self::OpenAiApiKey => config('ai.providers.openai.key'),
            self::SimilarityEnabled, self::SimilarityAutoCategorize => false,
            self::SimilaritySince => 'all',
            default => null,
        };
    }
}
