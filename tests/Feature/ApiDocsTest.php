<?php

use App\Models\User;
use App\Support\PlatformSettings;

test('guests are redirected to login when visiting the api docs', function () {
    User::factory()->create();

    $this->get('/docs/api')->assertRedirect(route('login'));
});

test('non-admin users cannot view the api docs or the spec', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/docs/api')->assertForbidden();
    $this->actingAs($user)->get('/docs/api.json')->assertForbidden();
});

test('platform admins can view the api docs and the generated spec', function () {
    $admin = User::factory()->platformAdmin()->create();
    app(PlatformSettings::class)->markOnboardingCompleted();

    $this->actingAs($admin)->get('/docs/api')->assertOk();

    $this->actingAs($admin)
        ->get('/docs/api.json')
        ->assertOk()
        ->assertJsonPath('paths./reports/overview.get.summary', 'Overview report')
        ->assertJsonPath('paths./reports/overview/categories.get.summary', 'Overview categories');
});
