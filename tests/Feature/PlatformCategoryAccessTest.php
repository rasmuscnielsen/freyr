<?php

use App\Models\User;
use App\Support\PlatformSettings;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to login', function () {
    User::factory()->create();

    $this->get(route('platform.categories.index'))
        ->assertRedirect(route('login'));
});

test('non-admin users are forbidden', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('platform.categories.index'))
        ->assertForbidden();
});

test('platform admins can view the categories index', function () {
    $admin = User::factory()->platformAdmin()->create();
    app(PlatformSettings::class)->markOnboardingCompleted();

    $this->actingAs($admin)
        ->get(route('platform.categories.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('platform/categories/index')
            ->has('categories')
            ->has('categoryTypes')
            ->has('expenseTypes'));
});

test('isPlatformAdmin is shared through inertia for admins and non-admins', function () {
    $admin = User::factory()->platformAdmin()->create();
    app(PlatformSettings::class)->markOnboardingCompleted();

    $this->actingAs($admin)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.isPlatformAdmin', true));

    $member = User::factory()->create();

    $this->actingAs($member)
        ->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.isPlatformAdmin', false));
});
