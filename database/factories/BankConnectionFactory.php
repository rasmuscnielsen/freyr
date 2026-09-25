<?php

namespace Database\Factories;

use App\Models\BankConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankConnection>
 */
class BankConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => BankConnection::PROVIDER_ENABLE_BANKING,
            'status' => BankConnection::STATUS_ACTIVE,
            'aspsp_name' => 'Example Bank',
            'aspsp_country' => 'DK',
            'psu_type' => 'personal',
            'authorization_id' => fake()->uuid(),
            'state' => fake()->uuid(),
            'session_id' => $sessionId = fake()->uuid(),
            'session_id_hash' => hash('sha256', $sessionId),
            'authorized_at' => now(),
            'access_valid_until' => now()->addDays(90),
            'raw_session_payload' => [
                'session_id' => $sessionId,
                'aspsp' => ['name' => 'Example Bank', 'country' => 'DK'],
            ],
        ];
    }

    public function renewalRequired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BankConnection::STATUS_RENEWAL_REQUIRED,
            'access_valid_until' => now()->subDay(),
            'failure_code' => 'CONSENT_EXPIRED',
            'failure_message' => 'The bank connection needs renewal.',
        ]);
    }

    public function forBank(string $name, string $country = 'DK'): static
    {
        return $this->state(fn (array $attributes): array => [
            'aspsp_name' => $name,
            'aspsp_country' => $country,
            'raw_session_payload' => [
                'session_id' => $attributes['session_id'] ?? null,
                'aspsp' => ['name' => $name, 'country' => $country],
            ],
        ]);
    }
}
