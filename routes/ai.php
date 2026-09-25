<?php

use App\Mcp\Servers\FreyrServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Passport;

// Advertise the app's full scope vocabulary (read/write/mcp:use) — the
// package's own metadata would only list `mcp:use`. Registering these exact
// routes before Mcp::oauthRoutes() makes the package keep ours.
$mcpScopes = fn () => Passport::scopeIds();

$authorizationServerMetadata = fn () => response()->json([
    'issuer' => config('mcp.authorization_server') ?? url('/'),
    'authorization_endpoint' => route('passport.authorizations.authorize'),
    'token_endpoint' => route('passport.token'),
    'registration_endpoint' => url('oauth/register'),
    'response_types_supported' => ['code'],
    'code_challenge_methods_supported' => ['S256'],
    'scopes_supported' => $mcpScopes(),
    'grant_types_supported' => ['authorization_code', 'refresh_token'],
]);

Route::get('/.well-known/oauth-authorization-server', $authorizationServerMetadata)
    ->name('mcp.oauth.authorization-server');

Route::get('/.well-known/oauth-protected-resource', function () use ($mcpScopes) {
    return response()->json([
        'resource' => url('/'),
        'authorization_servers' => [config('mcp.authorization_server') ?? url('/')],
        'scopes_supported' => $mcpScopes(),
    ]);
})->name('mcp.oauth.protected-resource');

Mcp::oauthRoutes();

// Same-URI routes replace earlier registrations, so these must come after
// Mcp::oauthRoutes() to shadow the package's own {path} metadata routes for
// clients that append the resource path to the metadata URL (RFC 9728).
Route::get('/.well-known/oauth-authorization-server/{path}', $authorizationServerMetadata)
    ->where('path', '.*');

Route::get('/.well-known/oauth-protected-resource/{path}', function (string $path) use ($mcpScopes) {
    return response()->json([
        'resource' => url('/'.$path),
        'authorization_servers' => [config('mcp.authorization_server') ?? url('/')],
        'scopes_supported' => $mcpScopes(),
    ]);
})->where('path', '.*')->name('mcp.oauth.protected-resource.nested');

Mcp::web('/mcp', FreyrServer::class)->middleware('auth:api');

Mcp::local('freyr', FreyrServer::class);
