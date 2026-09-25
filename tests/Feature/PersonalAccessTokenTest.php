<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('personal access tokens are listed on the security page', function () {
    providePersonalAccessClient();

    $user = User::factory()->create();
    $user->createToken('Local reports', ['read']);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => time()])
        ->get(route('security.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/security')
            ->has('personalAccessTokens', 1)
            ->where('personalAccessTokens.0.name', 'Local reports'),
        );
});

test('personal access tokens can be created', function () {
    providePersonalAccessClient();

    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('security.edit'))
        ->post(route('personal-access-tokens.store'), [
            'name' => 'Local scripts',
            'scopes' => ['read', 'write'],
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertSessionHas('plainTextPersonalAccessToken')
        ->assertRedirect(route('security.edit'));

    $token = $user->tokens()->sole();

    expect($token->name)->toBe('Local scripts')
        ->and($token->can('read'))->toBeTrue()
        ->and($token->can('write'))->toBeTrue()
        ->and($token->can('mcp:use'))->toBeFalse();
});

test('personal access tokens require at least one valid scope', function () {
    providePersonalAccessClient();

    $user = User::factory()->create();

    $this
        ->actingAs($user)
        ->from(route('security.edit'))
        ->post(route('personal-access-tokens.store'), [
            'name' => 'No scopes',
        ])
        ->assertSessionHasErrors('scopes');

    $this
        ->actingAs($user)
        ->from(route('security.edit'))
        ->post(route('personal-access-tokens.store'), [
            'name' => 'Bad scope',
            'scopes' => ['admin:everything'],
        ])
        ->assertSessionHasErrors('scopes.0');

    expect($user->tokens()->count())->toBe(0);
});

test('personal access tokens can be revoked', function () {
    providePersonalAccessClient();

    $user = User::factory()->create();
    $token = $user->createToken('Old token', ['read'])->token;

    $this
        ->actingAs($user)
        ->from(route('security.edit'))
        ->delete(route('personal-access-tokens.destroy', $token))
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));

    expect($user->tokens()->count())->toBe(0);
});

test('users cannot revoke another users personal access token', function () {
    providePersonalAccessClient();

    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $token = $otherUser->createToken('Other token', ['read'])->token;

    $this
        ->actingAs($user)
        ->delete(route('personal-access-tokens.destroy', $token))
        ->assertNotFound();

    expect($otherUser->tokens()->count())->toBe(1);
});
