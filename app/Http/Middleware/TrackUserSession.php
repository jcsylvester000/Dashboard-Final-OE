<?php

namespace App\Http\Middleware;

use App\Domain\Identity\SessionTracker;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * E-88: keeps the signed-in devices list current and signs out a request whose session
 * was ended from the security page or by an admin.
 */
class TrackUserSession
{
    public function __construct(private readonly SessionTracker $tracker) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // E-90: viewing as someone is not their session.
        if ($request->attributes->get('view_as') === true) {
            return $next($request);
        }
        if ($user instanceof User && ! $this->tracker->touch($request, $user)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson() ? response()->json(['message' => 'Signed out.'], 401) : redirect()->route('login');
        }

        return $next($request);
    }
}
