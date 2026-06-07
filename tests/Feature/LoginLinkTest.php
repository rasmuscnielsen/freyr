<?php

use App\Models\User;

test('local login link authenticates user with id 1', function () {
    config()->set('login-link.allowed_environments', ['testing']);

    $user = User::factory()->create(['id' => 1]);

    $response = $this->post(route('loginLinkLogin'), [
        'key' => '1',
        'redirect_url' => route('dashboard', absolute: false),
    ]);

    $this->assertAuthenticatedAs($user);
    $response->assertRedirect(route('dashboard', absolute: false));
});
