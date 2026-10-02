<?php

use App\Enums\PlatformSettingKey;
use App\Models\User;
use App\Support\PlatformSettings;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('new users can register', function () {
    User::factory()->create();
    app(PlatformSettings::class)->set(PlatformSettingKey::RegistrationEnabled, true);

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));
});

test('the registration screen returns 404 when registration is disabled', function () {
    User::factory()->create();

    $this->get(route('register'))->assertNotFound();
});

test('new users cannot register when registration is disabled', function () {
    User::factory()->create();

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();
    $this->assertGuest();
    expect(User::query()->count())->toBe(1);
});

test('the login page hides the sign up link when registration is disabled', function () {
    User::factory()->create();

    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('canRegister', false));
});
