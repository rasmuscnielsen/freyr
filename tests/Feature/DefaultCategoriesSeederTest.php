<?php

use App\Models\BankAccount;
use App\Models\Category;
use App\Models\Posting;
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

test('a spiir import replaces the default categories after confirmation', function () {
    $this->seed(DefaultCategoriesSeeder::class);

    $defaultSubcategory = Subcategory::query()
        ->where('legacy_id', DefaultCategoriesSeeder::SUBCATEGORY_LEGACY_ID_PREFIX.'groceries')
        ->firstOrFail();
    $bankAccount = BankAccount::factory()->create();
    $posting = Posting::factory()
        ->forAccount($bankAccount)
        ->categorizedAs($defaultSubcategory)
        ->create();

    $this->artisan('app:spiir:import', ['path' => base_path('tests/Fixtures/spiir-export')])
        ->expectsConfirmation('Continue?', 'yes')
        ->assertSuccessful();

    // Only the Spiir tree remains; postings that pointed at a default
    // subcategory are uncategorized now.
    expect(Category::query()->count())->toBe(3)
        ->and(Subcategory::query()->count())->toBe(3)
        ->and(Category::query()->where('legacy_id', 'like', DefaultCategoriesSeeder::CATEGORY_LEGACY_ID_PREFIX.'%')->exists())->toBeFalse()
        ->and($posting->refresh()->categorized_subcategory_id)->toBeNull();
});

test('a spiir import can be aborted at the default-categories warning', function () {
    $this->seed(DefaultCategoriesSeeder::class);

    $defaultCategories = Category::query()->count();

    $this->artisan('app:spiir:import', ['path' => base_path('tests/Fixtures/spiir-export')])
        ->expectsConfirmation('Continue?')
        ->assertFailed();

    expect(Category::query()->count())->toBe($defaultCategories);
});
