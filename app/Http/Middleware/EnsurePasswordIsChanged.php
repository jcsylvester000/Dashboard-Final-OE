<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Users holding an admin-issued temporary password must set their own
 * before they can use the app.
 */
class EnsurePasswordIsChanged
{
    /** Routes reachable while a password change is pending. */
    private const ALLOWED = ['password.force.edit', 'password.force.update', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null
            && $request->attributes->get('view_as') !== true // E-90: viewing as them changes nothing
            && $user->must_change_password
            && ! $request->routeIs(...self::ALLOWED)) {
            if ($request->expectsJson()) {
                abort(423, 'Password change required.');
            }

            return redirect()->route('password.force.edit');
        }

        return $next($request);
    }
}
