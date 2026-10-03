<?php

namespace App\Http\Middleware;

use App\Support\PlatformSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingNotCompleted
{
    public function __construct(private PlatformSettings $settings) {}

    /**
     * Keep the admin onboarding steps reachable only until the wizard is
     * finished; afterwards they redirect to the dashboard.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->settings->onboardingCompleted()) {
            return redirect()->route('dashboard');
        }

        return $next($request);
    }
}
