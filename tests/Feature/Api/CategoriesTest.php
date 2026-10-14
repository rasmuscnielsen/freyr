<?php

use App\Models\Category;
use App\Models\Subcategory;
use App\Models\User;
use Laravel\Passport\Passport;

test('categories can be read', function () {
    $category = Category::factory()->create(['name' => 'Food']);
    Subcategory::factory()->forCategory($category)->create(['name' => 'Groceries']);

    Passport::actingAs(User::factory()->create(), ['read']);

    $this->getJson('/api/categories')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Food')
        ->assertJsonPath('data.0.subcategories.0.name', 'Groceries');

    $this->getJson("/api/categories/{$category->id}")
        ->assertOk()
        ->assertJsonPath('data.name', 'Food');
});
