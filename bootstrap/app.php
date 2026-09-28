<?php

use App\Http\Middleware\EnsureNoUsers;
use App\Http\Middleware\EnsureOnboardingNotCompleted;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureRegionSetupCompleted;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RedirectToOnboarding;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            RedirectToOnboarding::class,
        ]);

        $middleware->alias([
            'platform-admin' => EnsurePlatformAdmin::class,
            'no-users' => EnsureNoUsers::class,
            'onboarding-not-completed' => EnsureOnboardingNotCompleted::class,
            'requires-region-setup' => EnsureRegionSetupCompleted::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            // /mcp: clients send `Accept: application/json, text/event-stream`
            // and cannot follow redirects — auth failures must stay JSON 401s.
            fn (Request $request) => $request->is('api/*') || $request->is('mcp'),
        );
    })->create();
