<?php

use App\Models\Category;
use App\Models\Subcategory;
use Database\Seeders\DefaultCategoriesSeeder;

test('it seeds the default category tree idempotently', function () {
    $this->seed(DefaultCategoriesSeeder::class);

    $categoryCount = Category::query()->count();
    $subcategoryCount = Subcategory::query()->count();

    expect($categoryCount)->toBeGreaterThan(0)
        ->and($subcategoryCount)->toBeGreaterThan($categoryCount)
        ->and(Subcategory::query()->whereNull('category_id')->count())->toBe(0);

    expect(Category::query()->pluck('type')->unique()->sort()->values()->all())
        ->toBe(['Expense', 'Income', 'Saving', 'Transfer']);

    $internalTransfer = Subcategory::query()
        ->where('legacy_id', DefaultCategoriesSeeder::SUBCATEGORY_LEGACY_ID_PREFIX.'internal_transfer')
        ->firstOrFail();
    expect($internalTransfer->is_ignored)->toBeTrue();

    $this->seed(DefaultCategoriesSeeder::class);

    expect(Category::query()->count())->toBe($categoryCount)
        ->and(Subcategory::query()->count())->toBe($subcategoryCount);
});

test('it does not clash with a spiir import', function () {
    $this->seed(DefaultCategoriesSeeder::class);

    $defaultCategories = Category::query()->count();
    $defaultSubcategories = Subcategory::query()->count();

    $this->artisan('app:spiir:import', ['path' => base_path('tests/Fixtures/spiir-export')])
        ->assertSuccessful();

    expect(Category::query()->count())->toBe($defaultCategories + 3)
        ->and(Subcategory::query()->count())->toBe($defaultSubcategories + 3);
});
