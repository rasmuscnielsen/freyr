<?php

use App\Models\User;

test('redirects guests to the login page', function () {
    User::factory()->create();

    $response = $this->get(route('home'));

    $response->assertRedirect(route('login'));
});
