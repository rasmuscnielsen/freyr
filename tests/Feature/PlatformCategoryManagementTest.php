<?php

use App\Enums\CategoryType;
use App\Enums\ExpenseGroup;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Support\Carbon;

function platformAdmin(): User
{
    app(PlatformSettings::class)->markOnboardingCompleted();

    return User::factory()->platformAdmin()->create();
}

test('moving a subcategory preserves its postings and applies the destination expense group', function (CategoryType $sourceType, CategoryType $destinationType, ?ExpenseGroup $expenseGroup) {
    $source = Category::factory()->create(['type' => $sourceType]);
    $destination = Category::factory()->create(['type' => $destinationType]);
    $subcategory = Subcategory::factory()->forCategory($source)->create([
        'expense_group' => $sourceType === CategoryType::Expense ? ExpenseGroup::Bills : null,
    ]);
    $posting = Posting::factory()->create(['categorized_subcategory_id' => $subcategory->id]);

    $this->actingAs(platformAdmin())
        ->patch(route('platform.subcategories.update', $subcategory), [
            'category_id' => $destination->id,
            'name' => $subcategory->name,
            'expense_group' => $expenseGroup?->value,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($subcategory->refresh()->category_id)->toBe($destination->id)
        ->and($subcategory->expense_group)->toBe($expenseGroup);
    expect($posting->refresh()->categorized_subcategory_id)->toBe($subcategory->id)
        ->and($posting->categorizedSubcategory->category->id)->toBe($destination->id);
})->with([
    'expense to expense' => [CategoryType::Expense, CategoryType::Expense, ExpenseGroup::Bills],
    'expense to expense unassigned' => [CategoryType::Expense, CategoryType::Expense, null],
    'expense to income' => [CategoryType::Expense, CategoryType::Income, null],
    'income to expense' => [CategoryType::Income, CategoryType::Expense, ExpenseGroup::Other],
]);

test('invalid subcategory destinations or expense groups leave the category unchanged', function (?int $categoryId, CategoryType $destinationType, ?string $expenseGroup, string $error) {
    $source = Category::factory()->create(['type' => CategoryType::Income]);
    $destination = Category::factory()->create(['type' => $destinationType]);
    $subcategory = Subcategory::factory()->forCategory($source)->create(['expense_group' => null]);

    $this->actingAs(platformAdmin())
        ->patch(route('platform.subcategories.update', $subcategory), [
            'category_id' => $categoryId === 0 ? $destination->id : $categoryId,
            'name' => $subcategory->name,
            'expense_group' => $expenseGroup,
        ])
        ->assertSessionHasErrors($error);

    expect($subcategory->refresh()->category_id)->toBe($source->id)
        ->and($subcategory->expense_group)->toBeNull();
})->with([
    'missing category' => [null, CategoryType::Income, null, 'category_id'],
    'unknown category' => [999999, CategoryType::Income, null, 'category_id'],
    'expense rejects unknown type' => [0, CategoryType::Expense, 'invalid', 'expense_group'],
    'income prohibits expense group' => [0, CategoryType::Income, ExpenseGroup::Bills->value, 'expense_group'],
]);

test('non-admin users cannot move subcategories', function () {
    app(PlatformSettings::class)->markOnboardingCompleted();
    $subcategory = Subcategory::factory()->create();
    $destination = Category::factory()->create();

    $this->actingAs(User::factory()->create())
        ->patch(route('platform.subcategories.update', $subcategory), [
            'category_id' => $destination->id,
            'name' => $subcategory->name,
        ])
        ->assertForbidden();

    expect($subcategory->refresh()->category_id)->not->toBe($destination->id);
});

test('a category can be created', function () {
    $this->actingAs(platformAdmin())
        ->post(route('platform.categories.store'), [
            'name' => 'Groceries',
            'type' => CategoryType::Expense->value,
        ])
        ->assertRedirect();

    $category = Category::query()->firstOrFail();

    expect($category->name)->toBe('Groceries')
        ->and($category->type)->toBe(CategoryType::Expense)
        ->and($category->hyphenated_name)->toBe('groceries')
        ->and($category->legacy_id)->toBeNull();
});

test('a category can be updated', function () {
    $category = Category::factory()->create(['name' => 'Old', 'type' => CategoryType::Income->value]);

    $this->actingAs(platformAdmin())
        ->patch(route('platform.categories.update', $category), [
            'name' => 'New',
            'type' => CategoryType::Saving->value,
        ])
        ->assertRedirect();

    expect($category->refresh()->name)->toBe('New')
        ->and($category->type)->toBe(CategoryType::Saving)
        ->and($category->hyphenated_name)->toBe('new');
});

test('an invalid category type is rejected', function () {
    $this->actingAs(platformAdmin())
        ->post(route('platform.categories.store'), [
            'name' => 'Bad',
            'type' => 'Nonsense',
        ])
        ->assertSessionHasErrors('type');

    expect(Category::query()->count())->toBe(0);
});

test('a category name is required and capped at 100 characters', function () {
    $admin = platformAdmin();

    $this->actingAs($admin)
        ->post(route('platform.categories.store'), [
            'name' => '',
            'type' => CategoryType::Expense->value,
        ])
        ->assertSessionHasErrors('name');

    $this->actingAs($admin)
        ->post(route('platform.categories.store'), [
            'name' => str_repeat('a', 101),
            'type' => CategoryType::Expense->value,
        ])
        ->assertSessionHasErrors('name');
});

test('expense_group may be unassigned for a subcategory under an expense category', function () {
    $category = Category::factory()->create(['type' => CategoryType::Expense->value]);

    $this->actingAs(platformAdmin())
        ->post(route('platform.subcategories.store'), [
            'category_id' => $category->id,
            'name' => 'Supermarket',
            'expense_group' => null,
        ])
        ->assertRedirect()->assertSessionHasNoErrors();

    expect(Subcategory::query()->firstOrFail()->expense_group)->toBeNull();
});

test('a subcategory under an expense category persists its expense_group', function () {
    $category = Category::factory()->create(['type' => CategoryType::Expense->value]);

    $this->actingAs(platformAdmin())
        ->post(route('platform.subcategories.store'), [
            'category_id' => $category->id,
            'name' => 'Rent',
            'expense_group' => ExpenseGroup::Bills->value,
        ])
        ->assertRedirect();

    $subcategory = Subcategory::query()->firstOrFail();

    expect($subcategory->name)->toBe('Rent')
        ->and($subcategory->expense_group)->toBe(ExpenseGroup::Bills)
        ->and($subcategory->hyphenated_name)->toBe('rent')
        ->and($subcategory->legacy_id)->toBeNull();
});

test('expense_group is rejected for a subcategory under a non-expense category', function () {
    $category = Category::factory()->create(['type' => CategoryType::Income->value]);

    $this->actingAs(platformAdmin())
        ->post(route('platform.subcategories.store'), [
            'category_id' => $category->id,
            'name' => 'Salary',
            'expense_group' => ExpenseGroup::Bills->value,
        ])
        ->assertSessionHasErrors('expense_group');
});

test('a subcategory under a non-expense category is stored with a null expense_group', function () {
    $category = Category::factory()->create(['type' => CategoryType::Income->value]);

    $this->actingAs(platformAdmin())
        ->post(route('platform.subcategories.store'), [
            'category_id' => $category->id,
            'name' => 'Salary',
        ])
        ->assertRedirect();

    expect(Subcategory::query()->firstOrFail()->expense_group)->toBeNull();
});

test('changing a category away from expense nulls its subcategory expense groups', function () {
    $category = Category::factory()->create(['type' => CategoryType::Expense->value]);
    $subcategory = Subcategory::factory()->forCategory($category)->create([
        'expense_group' => ExpenseGroup::Bills->value,
    ]);

    $this->actingAs(platformAdmin())
        ->patch(route('platform.categories.update', $category), [
            'name' => $category->name,
            'type' => CategoryType::Saving->value,
        ])
        ->assertRedirect();

    expect($subcategory->refresh()->expense_group)->toBeNull();
});

test('changing a category to expense leaves its subcategory groups unassigned', function () {
    $category = Category::factory()->create(['type' => CategoryType::Income->value]);
    $subcategory = Subcategory::factory()->forCategory($category)->create([
        'expense_group' => null,
    ]);

    $this->actingAs(platformAdmin())
        ->patch(route('platform.categories.update', $category), [
            'name' => $category->name,
            'type' => CategoryType::Expense->value,
        ])
        ->assertRedirect();

    expect($subcategory->refresh()->expense_group)->toBeNull();
});

test('deleting a subcategory uncategorizes its postings', function () {
    $category = Category::factory()->create(['type' => CategoryType::Expense->value]);
    $subcategory = Subcategory::factory()->forCategory($category)->create();

    Carbon::setTestNow('2026-05-01 10:00:00');
    $postings = Posting::factory()->count(3)->create([
        'categorized_subcategory_id' => $subcategory->id,
    ]);
    Carbon::setTestNow('2026-05-02 10:00:00');

    expect(Posting::query()->whereNotNull('categorized_subcategory_id')->count())->toBe(3);

    $this->actingAs(platformAdmin())
        ->delete(route('platform.subcategories.destroy', $subcategory))
        ->assertRedirect();

    expect(Subcategory::query()->whereKey($subcategory->id)->exists())->toBeFalse();

    foreach ($postings as $posting) {
        // Stamped so API `changed_since` sync sees the change.
        expect($posting->refresh())
            ->categorized_subcategory_id->toBeNull()
            ->updated_at->toDateTimeString()->toBe('2026-05-02 10:00:00');
    }
});

test('deleting a category cascades subcategories and uncategorizes postings', function () {
    $category = Category::factory()->create(['type' => CategoryType::Expense->value]);
    $subcategory = Subcategory::factory()->forCategory($category)->create();

    Carbon::setTestNow('2026-05-01 10:00:00');
    $postings = Posting::factory()->count(2)->create([
        'categorized_subcategory_id' => $subcategory->id,
    ]);
    Carbon::setTestNow('2026-05-02 10:00:00');

    $this->actingAs(platformAdmin())
        ->delete(route('platform.categories.destroy', $category))
        ->assertRedirect();

    expect(Category::query()->whereKey($category->id)->exists())->toBeFalse()
        ->and(Subcategory::query()->whereKey($subcategory->id)->exists())->toBeFalse();

    foreach ($postings as $posting) {
        // Stamped so API `changed_since` sync sees the change.
        expect($posting->refresh())
            ->categorized_subcategory_id->toBeNull()
            ->updated_at->toDateTimeString()->toBe('2026-05-02 10:00:00');
    }
});
