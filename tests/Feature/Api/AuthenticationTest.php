<?php

use App\Models\User;
use Laravel\Passport\Passport;

test('api routes require authentication', function () {
    $this->getJson('/api/bank-accounts')->assertUnauthorized();
});

test('api routes require a read token ability', function () {
    Passport::actingAs(User::factory()->create(), ['write']);

    $this->getJson('/api/bank-accounts')->assertForbidden();
    $this->getJson('/api/postings')->assertForbidden();
    // Authorization is checked before validation.
    $this->getJson('/api/postings?filter[date_from]=nope')->assertForbidden();
});

test('api resource routes are read only', function () {
    Passport::actingAs(User::factory()->create(), ['read']);

    $this->postJson('/api/bank-accounts', [])->assertMethodNotAllowed();
    $this->patchJson('/api/postings/1', [])->assertMethodNotAllowed();
    $this->deleteJson('/api/categories/1')->assertMethodNotAllowed();
});
