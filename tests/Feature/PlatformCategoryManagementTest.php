<?php

use App\Enums\CategoryType;
use App\Enums\ExpenseType;
use App\Models\Category;
use App\Models\Posting;
use App\Models\Subcategory;
use App\Models\User;

function platformAdmin(): User
{
    config(['custom.admin_emails' => ['admin@example.com']]);

    return User::factory()->create(['email' => 'admin@example.com']);
}

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

test('expense_type is required for a subcategory under an expense category', function () {
    $category = Category::factory()->create(['type' => CategoryType::Expense->value]);

    $this->actingAs(platformAdmin())
        ->post(route('platform.subcategories.store'), [
            'category_id' => $category->id,
            'name' => 'Supermarket',
        ])
        ->assertSessionHasErrors('expense_type');
});

test('a subcategory under an expense category persists its expense_type', function () {
    $category = Category::factory()->create(['type' => CategoryType::Expense->value]);

    $this->actingAs(platformAdmin())
        ->post(route('platform.subcategories.store'), [
            'category_id' => $category->id,
            'name' => 'Rent',
            'expense_type' => ExpenseType::Fixed->value,
        ])
        ->assertRedirect();

    $subcategory = Subcategory::query()->firstOrFail();

    expect($subcategory->name)->toBe('Rent')
        ->and($subcategory->expense_type)->toBe(ExpenseType::Fixed)
        ->and($subcategory->hyphenated_name)->toBe('rent')
        ->and($subcategory->legacy_id)->toBeNull();
});

test('expense_type is rejected for a subcategory under a non-expense category', function () {
    $category = Category::factory()->create(['type' => CategoryType::Income->value]);

    $this->actingAs(platformAdmin())
        ->post(route('platform.subcategories.store'), [
            'category_id' => $category->id,
            'name' => 'Salary',
            'expense_type' => ExpenseType::Fixed->value,
        ])
        ->assertSessionHasErrors('expense_type');
});

test('a subcategory under a non-expense category is stored with a null expense_type', function () {
    $category = Category::factory()->create(['type' => CategoryType::Income->value]);

    $this->actingAs(platformAdmin())
        ->post(route('platform.subcategories.store'), [
            'category_id' => $category->id,
            'name' => 'Salary',
        ])
        ->assertRedirect();

    expect(Subcategory::query()->firstOrFail()->expense_type)->toBeNull();
});

test('changing a category away from expense nulls its subcategory expense types', function () {
    $category = Category::factory()->create(['type' => CategoryType::Expense->value]);
    $subcategory = Subcategory::factory()->forCategory($category)->create([
        'expense_type' => ExpenseType::Fixed->value,
    ]);

    $this->actingAs(platformAdmin())
        ->patch(route('platform.categories.update', $category), [
            'name' => $category->name,
            'type' => CategoryType::Saving->value,
        ])
        ->assertRedirect();

    expect($subcategory->refresh()->expense_type)->toBeNull();
});

test('changing a category to expense defaults its subcategory expense types to variable', function () {
    $category = Category::factory()->create(['type' => CategoryType::Income->value]);
    $subcategory = Subcategory::factory()->forCategory($category)->create([
        'expense_type' => null,
    ]);

    $this->actingAs(platformAdmin())
        ->patch(route('platform.categories.update', $category), [
            'name' => $category->name,
            'type' => CategoryType::Expense->value,
        ])
        ->assertRedirect();

    expect($subcategory->refresh()->expense_type)->toBe(ExpenseType::Variable);
});

test('deleting a subcategory uncategorizes its postings', function () {
    $category = Category::factory()->create(['type' => CategoryType::Expense->value]);
    $subcategory = Subcategory::factory()->forCategory($category)->create();

    $postings = Posting::factory()->count(3)->create([
        'categorized_subcategory_id' => $subcategory->id,
    ]);

    expect(Posting::query()->whereNotNull('categorized_subcategory_id')->count())->toBe(3);

    $this->actingAs(platformAdmin())
        ->delete(route('platform.subcategories.destroy', $subcategory))
        ->assertRedirect();

    expect(Subcategory::query()->whereKey($subcategory->id)->exists())->toBeFalse();

    foreach ($postings as $posting) {
        expect($posting->refresh()->categorized_subcategory_id)->toBeNull();
    }
});

test('deleting a category cascades subcategories and uncategorizes postings', function () {
    $category = Category::factory()->create(['type' => CategoryType::Expense->value]);
    $subcategory = Subcategory::factory()->forCategory($category)->create();

    $postings = Posting::factory()->count(2)->create([
        'categorized_subcategory_id' => $subcategory->id,
    ]);

    $this->actingAs(platformAdmin())
        ->delete(route('platform.categories.destroy', $category))
        ->assertRedirect();

    expect(Category::query()->whereKey($category->id)->exists())->toBeFalse()
        ->and(Subcategory::query()->whereKey($subcategory->id)->exists())->toBeFalse();

    foreach ($postings as $posting) {
        expect($posting->refresh()->categorized_subcategory_id)->toBeNull();
    }
});
