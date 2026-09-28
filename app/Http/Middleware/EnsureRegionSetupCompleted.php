<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every user must complete the personal region setup (country, timezone,
 * reporting currency) before using the app — accounts, imports, and bank
 * sync all depend on a fixed reporting currency. The setup routes
 * themselves and the platform onboarding wizard stay reachable.
 */
class EnsureRegionSetupCompleted
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs('region-setup.*')) {
            return $next($request);
        }

        $user = $request->user();

        if ($user !== null && ! $user->hasCompletedRegionSetup()) {
            return redirect()->route('region-setup.edit');
        }

        return $next($request);
    }
}
