<?php

namespace Database\Factories;

use App\Models\Category;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Category>
 */
class CategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'legacy_id' => fake()->unique()->numerify('legacy-category-####'),
            'name' => fake()->words(2, true),
            'type' => 'Expense',
            'hyphenated_name' => null,
        ];
    }

    public function income(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'Income',
        ]);
    }

    public function exclude(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'Exclude',
        ]);
    }
}
