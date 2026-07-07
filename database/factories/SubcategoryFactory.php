<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\Subcategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subcategory>
 */
class SubcategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $category = Category::factory();

        return [
            'category_id' => $category,
            'legacy_id' => fake()->unique()->numerify('legacy-subcategory-####'),
            'name' => fake()->words(2, true),
            'hyphenated_name' => null,
            'expense_type' => 'Variable',
            'hints' => null,
        ];
    }

    public function forCategory(Category $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category_id' => $category->id,
        ]);
    }
}
