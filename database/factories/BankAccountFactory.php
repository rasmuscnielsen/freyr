<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BankAccount>
 */
class BankAccountFactory extends Factory
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
            'legacy_id' => fake()->unique()->numerify('legacy-account-####'),
            'name' => fake()->randomElement(['Checking', 'Savings', 'Sample Account']),
            'bank_key' => fake()->randomElement(['ExampleBank', 'DK_SampleBank', 'OtherBank']),
            'bank_name' => fake()->randomElement(['ExampleBank', 'SampleBank', 'Other Bank']),
            'currency' => 'DKK',
            'current_balance' => fake()->randomFloat(2, 0, 100000),
            'opening_balance' => null,
            'account_type' => 'Consumption',
            'ignore_postings_before' => null,
            'last_updated_at' => now(),
            'last_known_posting_date' => now()->toDateString(),
            'is_active' => true,
            'account_number' => null,
            'is_favorite' => false,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    public function favorite(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_favorite' => true,
        ]);
    }

    public function withBalance(float $balance): static
    {
        return $this->state(fn (array $attributes): array => [
            'current_balance' => $balance,
        ]);
    }
}
