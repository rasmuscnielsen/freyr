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

// The --demo path (migrate:fresh + DemoSeeder) cannot run inside
// RefreshDatabase's transaction; its seeding behavior is covered by
// DemoSeederTest instead.

test('app:install asks for confirmation in production', function () {
    $this->app['env'] = 'production';

    $this->artisan('app:install')
        ->expectsConfirmation('Are you sure you want to run this command?')
        ->assertFailed();

    expect(Category::query()->count())->toBe(0);
});

test('app:install --force skips the production confirmation', function () {
    $this->app['env'] = 'production';

    $this->artisan('app:install', ['--force' => true])
        ->assertSuccessful();

    expect(Category::query()->count())->toBeGreaterThan(0);
});

test('app:deployed runs migrations', function () {
    $this->artisan('app:deployed')
        ->assertSuccessful();
});
