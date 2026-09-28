<?php

namespace Database\Factories;

use App\Models\BankAccount;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Posting>
 */
class PostingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, -1000, -10);

        return [
            'user_id' => User::factory(),
            'bank_account_id' => BankAccount::factory(),
            'subcategory_id' => null,
            'categorized_subcategory_id' => Subcategory::factory(),
            'counter_entry_posting_id' => null,
            'legacy_id' => fake()->unique()->numerify('legacy-posting-########'),
            'counter_entry_legacy_id' => null,
            'split_group_legacy_id' => null,
            'date' => fake()->dateTimeBetween('-1 year')->format('Y-m-d'),
            'custom_date' => null,
            'description' => fake()->company(),
            'original_description' => null,
            'comment' => null,
            'amount' => $amount,
            'original_amount' => $amount,
            'balance' => null,
            'upload_batch_id' => null,
            'external_posting_id' => null,
            'document_legacy_id' => null,
            'is_extraordinary' => false,
            'is_account_group_duplicate' => false,
            'prevent_marking_as_counter_entry' => false,
            'description_changed' => false,
            'extra_data' => null,
            'posting_type' => 0,
            'posting_sub_type' => null,
            'currency' => 'DKK',
            'original_currency' => 'DKK',
            'merchant_legacy_id' => null,
            'country_code' => 'DK',
            'state' => null,
            'carbon_emission_in_grams' => null,
            'categorized_by' => null,
            'legacy_created_at' => now(),
        ];
    }

    public function forAccount(BankAccount $bankAccount): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $bankAccount->user_id,
            'bank_account_id' => $bankAccount->id,
        ]);
    }

    public function categorizedAs(Subcategory $subcategory): static
    {
        return $this->state(fn (array $attributes) => [
            'categorized_subcategory_id' => $subcategory->id,
            'subcategory_id' => $subcategory->id,
        ]);
    }

    public function income(): static
    {
        return $this->state(function (array $attributes): array {
            $amount = fake()->randomFloat(2, 1000, 50000);

            return ['amount' => $amount, 'original_amount' => $amount];
        });
    }

    public function expense(): static
    {
        return $this->state(function (array $attributes): array {
            $amount = fake()->randomFloat(2, -1000, -10);

            return ['amount' => $amount, 'original_amount' => $amount];
        });
    }

    public function saving(): static
    {
        return $this->state(function (array $attributes): array {
            $amount = fake()->randomFloat(2, -10000, -500);

            return ['amount' => $amount, 'original_amount' => $amount];
        });
    }

    /**
     * A posting whose account movement is known in $accountCurrency but whose
     * reporting amount is still pending conversion.
     */
    public function unconverted(string $accountCurrency = 'EUR'): static
    {
        return $this->state(function (array $attributes) use ($accountCurrency): array {
            $amount = fake()->randomFloat(2, -1000, -10);

            return [
                'amount' => null,
                'original_amount' => $amount,
                'original_currency' => $accountCurrency,
            ];
        });
    }

    public function uncategorized(): static
    {
        return $this->state(fn (array $attributes) => [
            'categorized_subcategory_id' => null,
            'subcategory_id' => null,
        ]);
    }
}
