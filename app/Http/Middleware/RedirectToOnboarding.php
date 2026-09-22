<?php

namespace App\Http\Middleware;

use App\Platform\PlatformSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RedirectToOnboarding
{
    public function __construct(private PlatformSettings $settings) {}

    /**
     * Send brand-new installs (no users at all) to the onboarding wizard, and
     * platform admins who have not finished it back to the first post-account
     * step. Onboarding and logout routes are always reachable.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            if (! Schema::hasTable('users')) {
                return $next($request);
            }
        } catch (Throwable) {
            return $next($request);
        }

        if ($request->routeIs('onboarding.*') || $request->routeIs('logout')) {
            return $next($request);
        }

        if ($this->settings->needsFirstUser()) {
            return redirect()->route('onboarding.welcome');
        }

        if ($request->user()?->isPlatformAdmin() && ! $this->settings->onboardingCompleted()) {
            return redirect()->route('onboarding.registration');
        }

        return $next($request);
    }
}
