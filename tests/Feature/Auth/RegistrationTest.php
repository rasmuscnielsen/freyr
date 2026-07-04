<?php

use App\Models\User;
use Laravel\Fortify\Features;

beforeEach(function () {
    $this->skipUnlessFortifyHas(Features::registration());
});

test('registration screen can be rendered', function () {
    $response = $this->get(route('register'));

    $response->assertOk();
});

test('new users can register', function () {
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
    config()->set('custom.registration_enabled', false);

    $this->get(route('register'))->assertNotFound();
});

test('new users cannot register when registration is disabled', function () {
    config()->set('custom.registration_enabled', false);

    $response = $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();
    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
});

test('the login page hides the sign up link when registration is disabled', function () {
    config()->set('custom.registration_enabled', false);

    $this->get(route('login'))
        ->assertInertia(fn ($page) => $page->where('canRegister', false));
});
