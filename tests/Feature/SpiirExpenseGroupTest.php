<?php

use App\Enums\ExpenseGroup;
use App\Models\Category;
use App\Models\Subcategory;
use App\Services\Spiir\Importer;
use Illuminate\Support\Facades\File;

/**
 * Exercise real source identities without importing accounts or postings.
 *
 * @param  array<string, mixed>  $payload
 */
function importExpenseTaxonomy(array $payload): void
{
    $path = tempnam(sys_get_temp_dir(), 'spiir-groups-');
    File::delete($path);
    File::makeDirectory($path);
    File::put($path.'/Categories.json', json_encode($payload, JSON_THROW_ON_ERROR));
    try {
        app(Importer::class)->importCategories($path);
    } finally {
        File::deleteDirectory($path);
    }
}

test('fresh Spiir categories use the known grouping regardless of the old flag', function (string $id, string $name, string $parentId, ExpenseGroup $expected) {
    importExpenseTaxonomy([
        'categories' => [['id' => $parentId, 'name' => 'Imported', 'categoryType' => 'Expense']],
        'subcategories' => [['id' => $id, 'categoryId' => $parentId, 'name' => $name, 'expenseType' => 'Fixed']],
    ]);

    expect(Subcategory::query()->where('legacy_id', $id)->firstOrFail()->expense_group)->toBe($expected);
})->with([
    'utility bill with changing amount' => ['115', 'El, vand, varme & renovation', '12', ExpenseGroup::Bills],
    'groceries' => ['132', 'Dagligvarer', '14', ExpenseGroup::LivingCosts],
    'home and garden upkeep' => ['195', 'Have & planter', '12', ExpenseGroup::LivingCosts],
    'restaurant' => ['156', 'Bar, cafe & restaurant', '17', ExpenseGroup::Lifestyle],
    'holiday home formerly Fixed' => ['121', 'Udgifter fritidshus', '12', ExpenseGroup::Other],
]);

test('unknown or mismatched Spiir identities remain unassigned', function (string $id, string $name, string $parentId) {
    importExpenseTaxonomy([
        'categories' => [['id' => $parentId, 'name' => 'Imported', 'categoryType' => 'Expense']],
        'subcategories' => [['id' => $id, 'categoryId' => $parentId, 'name' => $name, 'expenseType' => 'Variable']],
    ]);

    expect(Subcategory::query()->firstOrFail()->expense_group)->toBeNull();
})->with([
    'unknown source id' => ['999', 'Dagligvarer', '14'],
    'known id with different name' => ['132', 'Custom groceries', '14'],
    'known id under unexpected source parent' => ['132', 'Dagligvarer', '12'],
]);

test('reimport preserves curated groups and fills unassigned groups but clears nonexpenses', function () {
    $category = Category::factory()->create(['legacy_id' => '14']);
    $subcategory = Subcategory::factory()->forCategory($category)->create([
        'legacy_id' => '132', 'name' => 'Dagligvarer', 'expense_group' => ExpenseGroup::Lifestyle,
    ]);
    $payload = [
        'categories' => [['id' => '14', 'name' => 'Husholdning', 'categoryType' => 'Expense']],
        'subcategories' => [['id' => '132', 'categoryId' => '14', 'name' => 'Dagligvarer']],
    ];

    importExpenseTaxonomy($payload);
    expect($subcategory->refresh()->expense_group)->toBe(ExpenseGroup::Lifestyle);

    $subcategory->update(['expense_group' => null]);
    importExpenseTaxonomy($payload);
    expect($subcategory->refresh()->expense_group)->toBe(ExpenseGroup::LivingCosts);

    $payload['categories'][0]['categoryType'] = 'Income';
    importExpenseTaxonomy($payload);
    expect($subcategory->refresh()->expense_group)->toBeNull();
});

test('backfill previews changes and applies only eligible null groups idempotently', function () {
    $housing = Category::factory()->create(['legacy_id' => '12']);
    $food = Category::factory()->create(['legacy_id' => '14']);
    $rent = Subcategory::factory()->forCategory($housing)->create([
        'legacy_id' => '114', 'name' => 'Boliglån/husleje', 'expense_group' => null,
    ]);
    $curated = Subcategory::factory()->forCategory($food)->create([
        'legacy_id' => '132', 'name' => 'Dagligvarer', 'expense_group' => ExpenseGroup::Lifestyle,
    ]);
    $unknown = Subcategory::factory()->forCategory($food)->create([
        'legacy_id' => '999', 'name' => 'Unknown source',
    ]);
    $mismatched = Subcategory::factory()->forCategory($housing)->create([
        'legacy_id' => '115', 'name' => 'Custom name',
    ]);
    $nonexpense = Subcategory::factory()->forCategory(Category::factory()->income()->create())->create([
        'expense_group' => ExpenseGroup::Bills,
    ]);

    $this->artisan('spiir:backfill-expense-groups')
        ->expectsOutput('Preview only; use --apply to write these changes.')
        ->expectsOutput('1 assignments; 1 non-expense clearings; 2 unmapped.')
        ->assertSuccessful();

    expect($rent->refresh()->expense_group)->toBeNull()
        ->and($nonexpense->refresh()->expense_group)->toBe(ExpenseGroup::Bills);

    $this->artisan('spiir:backfill-expense-groups', ['--apply' => true])
        ->expectsOutput('2 rows changed.')->assertSuccessful();

    expect($rent->refresh()->expense_group)->toBe(ExpenseGroup::Bills)
        ->and($curated->refresh()->expense_group)->toBe(ExpenseGroup::Lifestyle)
        ->and($unknown->refresh()->expense_group)->toBeNull()
        ->and($mismatched->refresh()->expense_group)->toBeNull()
        ->and($nonexpense->refresh()->expense_group)->toBeNull();

    $this->artisan('spiir:backfill-expense-groups', ['--apply' => true])
        ->expectsOutput('0 rows changed.')->assertSuccessful();
});
