<?php

use App\Models\Posting;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('a user without region setup is redirected from the app to the setup page', function () {
    $user = User::factory()->withoutRegionSetup()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertRedirect(route('region-setup.edit'));
});

test('the setup page stays reachable while setup is incomplete', function () {
    $user = User::factory()->withoutRegionSetup()->create();

    $this->actingAs($user)
        ->get(route('region-setup.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('region-setup')
            ->has('countries')
            ->has('timezones')
            ->has('currencies'));
});

test('submitting the setup stores the region preferences and unlocks the app', function () {
    $user = User::factory()->withoutRegionSetup()->create();

    $this->actingAs($user)
        ->post(route('region-setup.store'), [
            'country_code' => 'DK',
            'timezone' => 'Europe/Copenhagen',
            'reporting_currency' => 'DKK',
        ])
        ->assertRedirect(route('dashboard'));

    $user->refresh();

    expect($user->country_code)->toBe('DK')
        ->and($user->timezone)->toBe('Europe/Copenhagen')
        ->and($user->reporting_currency)->toBe('DKK')
        ->and($user->region_completed_at)->not->toBeNull();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});

test('the setup validates the timezone against the chosen country', function () {
    $user = User::factory()->withoutRegionSetup()->create();

    $this->actingAs($user)
        ->post(route('region-setup.store'), [
            'country_code' => 'DK',
            'timezone' => 'America/New_York',
            'reporting_currency' => 'DKK',
        ])
        ->assertSessionHasErrors('timezone');

    expect($user->refresh()->region_completed_at)->toBeNull();
});

test('the reporting currency is rejected when it is not ECB-covered', function () {
    $user = User::factory()->withoutRegionSetup()->create();

    $this->actingAs($user)
        ->post(route('region-setup.store'), [
            'country_code' => 'DK',
            'timezone' => 'Europe/Copenhagen',
            'reporting_currency' => 'XXX',
        ])
        ->assertSessionHasErrors('reporting_currency');
});

test('profile edits cannot change the reporting currency', function () {
    $user = User::factory()->create(['reporting_currency' => 'DKK']);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'reporting_currency' => 'EUR',
        ])
        ->assertSessionHasErrors('reporting_currency');

    expect($user->refresh()->reporting_currency)->toBe('DKK');
});

test('profile edits can change country and timezone', function () {
    $user = User::factory()->create([
        'country_code' => 'DK',
        'timezone' => 'Europe/Copenhagen',
        'reporting_currency' => 'DKK',
    ]);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'country_code' => 'SE',
            'timezone' => 'Europe/Stockholm',
        ])
        ->assertSessionHasNoErrors();

    expect($user->refresh()->country_code)->toBe('SE')
        ->and($user->timezone)->toBe('Europe/Stockholm')
        ->and($user->reporting_currency)->toBe('DKK');
});

test('a spiir-set reporting currency is not re-opened by completing setup', function () {
    $user = User::factory()->withoutRegionSetup()->create(['reporting_currency' => 'EUR']);
    Posting::factory()->for($user)->create();

    $this->actingAs($user)
        ->post(route('region-setup.store'), [
            'country_code' => 'DK',
            'timezone' => 'Europe/Copenhagen',
            'reporting_currency' => 'DKK',
        ])
        ->assertRedirect(route('dashboard'));

    expect($user->refresh()->reporting_currency)->toBe('EUR');
});
