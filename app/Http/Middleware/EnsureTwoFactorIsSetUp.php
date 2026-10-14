<?php

namespace App\Http\Middleware;

use App\Domain\Identity\TwoFactorPolicy;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * E-87: after the grace period, people who must use two-factor sign-in can only reach
 * the pages that set it up (and sign out) until they do.
 */
class EnsureTwoFactorIsSetUp
{
    /** Reachable while two-factor setup is overdue. */
    private const ALLOWED = [
        'security.edit', 'user-password.update', 'two-factor.*', 'passkey.*', 'password.confirm*',
        'password.force.*', 'logout', 'login', 'appearance.edit',
    ];

    public function __construct(private readonly TwoFactorPolicy $policy) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || $request->routeIs(...self::ALLOWED) || $request->is('api/*')) {
            return $next($request);
        }

        $deadline = $this->policy->deadline($user);
        $request->attributes->set('two_factor_deadline', $deadline); // the banner reads it
        if ($deadline !== null && $deadline->isPast()) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                abort(423, 'Two-factor sign-in must be set up first.');
            }
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Set up two-factor sign-in to continue. Your role needs it.')]);

            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}
