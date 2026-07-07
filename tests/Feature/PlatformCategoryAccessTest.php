<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login', function () {
    $this->get(route('platform.categories.index'))
        ->assertRedirect(route('login'));
});

test('non-admin users are forbidden', function () {
    config(['custom.admin_emails' => ['admin@example.com']]);

    $user = User::factory()->create(['email' => 'member@example.com']);

    $this->actingAs($user)
        ->get(route('platform.categories.index'))
        ->assertForbidden();
});

test('platform admins can view the categories index', function () {
    config(['custom.admin_emails' => ['admin@example.com']]);

    $admin = User::factory()->create(['email' => 'admin@example.com']);

    $this->actingAs($admin)
        ->get(route('platform.categories.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/categories/index')
            ->has('categories')
            ->has('categoryTypes')
            ->has('expenseTypes'));
});

test('admin identity is case-insensitive and guards empty config', function () {
    config(['custom.admin_emails' => ['Admin@Example.com']]);

    $admin = User::factory()->make(['email' => 'admin@example.com']);
    expect($admin->isPlatformAdmin())->toBeTrue();

    config(['custom.admin_emails' => ['']]);

    $user = User::factory()->make(['email' => 'admin@example.com']);
    expect($user->isPlatformAdmin())->toBeFalse();
});

test('isPlatformAdmin is shared through inertia for admins and non-admins', function () {
    config(['custom.admin_emails' => ['admin@example.com']]);

    $admin = User::factory()->create(['email' => 'admin@example.com']);

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.isPlatformAdmin', true));

    $member = User::factory()->create(['email' => 'member@example.com']);

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.isPlatformAdmin', false));
});
