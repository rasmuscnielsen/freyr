<?php

use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

test('well-known endpoints advertise every scope and the passport endpoints', function () {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
        ->assertJsonPath('token_endpoint', route('passport.token'))
        ->assertJsonPath('registration_endpoint', url('oauth/register'))
        ->assertJsonPath('code_challenge_methods_supported', ['S256'])
        ->assertJsonPath('scopes_supported', ['read', 'write', 'mcp:use']);

    $this->getJson('/.well-known/oauth-protected-resource')
        ->assertOk()
        ->assertJsonPath('scopes_supported', ['read', 'write', 'mcp:use']);

    // Clients may append the resource path to the metadata URL (RFC 9728).
    $this->getJson('/.well-known/oauth-protected-resource/mcp')
        ->assertOk()
        ->assertJsonPath('scopes_supported', ['read', 'write', 'mcp:use'])
        ->assertJsonPath('resource', url('/mcp'));

    $this->getJson('/.well-known/oauth-authorization-server/mcp')
        ->assertOk()
        ->assertJsonPath('scopes_supported', ['read', 'write', 'mcp:use']);
});

test('clients can self-register through dynamic client registration', function () {
    $this->postJson('/oauth/register', [
        'client_name' => 'Cursor',
        'redirect_uris' => ['cursor://anypath/callback'],
    ])
        ->assertCreated()
        ->assertJsonStructure(['client_id', 'redirect_uris', 'scope'])
        ->assertJsonPath('scope', 'mcp:use');
});

test('authorization code + PKCE flow issues a token that works on /mcp', function () {
    $user = User::factory()->create();

    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        name: 'Claude',
        redirectUris: ['https://claude.ai/api/mcp/auth_callback'],
        confidential: false,
    );

    $verifier = Str::random(128);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $redirectUri = 'https://claude.ai/api/mcp/auth_callback';

    // Consent screen for a logged-in user.
    $this->actingAs($user)
        ->get('/oauth/authorize?'.http_build_query([
            'response_type' => 'code',
            'client_id' => (string) $client->id,
            'redirect_uri' => $redirectUri,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'state' => 'client-state',
            'scope' => 'mcp:use read',
        ]))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/authorize')
            ->where('client.name', 'Claude')
            ->has('scopes', 2)
            ->has('authToken')
            ->has('csrfToken'));

    // User approves → redirect back to the client with a code.
    $response = $this->actingAs($user)
        ->post('/oauth/authorize', ['auth_token' => session('authToken')])
        ->assertRedirect();

    $query = [];
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

    expect($query)->toHaveKey('code');
    expect($query['state'] ?? null)->toBe('client-state');

    // Client exchanges the code + verifier for a token (no secret — public client).
    $tokenResponse = $this->postJson('/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => (string) $client->id,
        'code' => $query['code'],
        'redirect_uri' => $redirectUri,
        'code_verifier' => $verifier,
    ])->assertOk();

    $accessToken = $tokenResponse->json('access_token');
    expect($accessToken)->toBeString();

    // The OAuth-issued token reaches the MCP server.
    $this->withToken($accessToken)
        ->postJson('/mcp', [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-03-26',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ])
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'Freyr');
});

test('mcp:use is layered on top of read and write scopes', function () {
    $user = User::factory()->create();

    Passport::actingAs($user, ['mcp:use', 'read']);

    // Read scopes pass, but write tools still require 'write' on top.
    expect($user->tokenCan('mcp:use'))->toBeTrue();
    expect($user->tokenCan('read'))->toBeTrue();
    expect($user->tokenCan('write'))->toBeFalse();
});
