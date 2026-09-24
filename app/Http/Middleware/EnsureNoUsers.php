<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNoUsers
{
    /**
     * Restrict the public onboarding step to installs that do not have any
     * users yet; everyone else is sent to the login screen.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (User::query()->exists()) {
            return redirect()->route('login');
        }

        return $next($request);
    }
}
