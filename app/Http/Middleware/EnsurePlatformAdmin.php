<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlatformAdmin
{
    /**
     * Ensure the authenticated user is a platform administrator, aborting with
     * a 403 otherwise. Authentication itself is expected to be enforced by the
     * `auth` middleware earlier in the stack.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isPlatformAdmin() ?? false, 403);

        return $next($request);
    }
}
