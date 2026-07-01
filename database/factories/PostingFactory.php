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
            'amount' => fake()->randomFloat(2, -1000, -10),
            'original_amount' => null,
            'balance' => fake()->randomFloat(2, 0, 100000),
            'upload_batch_id' => null,
            'external_posting_id' => null,
            'document_legacy_id' => null,
            'is_extraordinary' => false,
            'is_account_group_duplicate' => false,
            'prevent_marking_as_counter_entry' => false,
            'description_changed' => false,
            'extra_data' => null,
            'category_type_code' => 2,
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
        return $this->state(fn (array $attributes) => [
            'amount' => fake()->randomFloat(2, 1000, 50000),
            'category_type_code' => Posting::CATEGORY_TYPE_INCOME,
        ]);
    }

    public function expense(): static
    {
        return $this->state(fn (array $attributes): array => [
            'amount' => fake()->randomFloat(2, -1000, -10),
            'category_type_code' => Posting::CATEGORY_TYPE_EXPENSE,
        ]);
    }

    public function saving(): static
    {
        return $this->state(fn (array $attributes): array => [
            'amount' => fake()->randomFloat(2, -10000, -500),
            'category_type_code' => Posting::CATEGORY_TYPE_SAVING,
        ]);
    }

    public function transfer(): static
    {
        return $this->state(fn (array $attributes): array => [
            'category_type_code' => Posting::CATEGORY_TYPE_TRANSFER,
        ]);
    }

    public function uncategorized(): static
    {
        return $this->state(fn (array $attributes) => [
            'categorized_subcategory_id' => null,
            'subcategory_id' => null,
        ]);
    }
}
