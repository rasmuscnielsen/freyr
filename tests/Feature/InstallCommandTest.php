<?php

use App\Models\Category;

test('app:install migrates and seeds the default categories', function () {
    $this->artisan('app:install')
        ->assertSuccessful();

    expect(Category::query()->count())->toBeGreaterThan(0);

    // Idempotent: safe to run again.
    $categoryCount = Category::query()->count();

    $this->artisan('app:install')
        ->assertSuccessful();

    expect(Category::query()->count())->toBe($categoryCount);
});

test('app:install --demo is refused outside the local environment', function () {
    $this->artisan('app:install', ['--demo' => true])
        ->assertFailed();
});

test('app:deployed runs migrations', function () {
    $this->artisan('app:deployed')
        ->assertSuccessful();
});
