<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\BankConnection;
use App\Models\BankConnectionAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankConnectionAccount>
 */
class BankConnectionAccountFactory extends Factory
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
            'bank_connection_id' => BankConnection::factory(),
            'status' => BankConnectionAccount::STATUS_UNLINKED,
            'external_account_uid' => $uid = fake()->uuid(),
            'external_account_uid_hash' => hash('sha256', $uid),
            'identification_hash' => fake()->sha256(),
            'identification_hashes' => [fake()->sha256()],
            'name' => 'Sandbox User',
            'details' => fake()->randomElement(['Everyday', 'Shared']),
            'currency' => 'DKK',
            'iban' => 'DK5000000000000001',
            'account_number' => '00000000000001',
            'account_number_last_four' => '0001',
            'bic' => 'TESTDKKK',
            'product' => 'Ung Konto',
            'cash_account_type' => 'CACC',
            'raw_account_payload' => [
                'uid' => $uid,
                'currency' => 'DKK',
            ],
        ];
    }

    public function linked(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => BankConnectionAccount::STATUS_LINKED,
            'bank_account_id' => BankAccount::factory(),
        ]);
    }

    public function linkedTo(BankAccount $bankAccount): static
    {
        return $this->state(fn (array $attributes): array => [
            'user_id' => $bankAccount->user_id,
            'status' => BankConnectionAccount::STATUS_LINKED,
            'bank_account_id' => $bankAccount->id,
        ]);
    }
}
