<?php

namespace App\Http\Middleware;

use App\Domain\Identity\ViewAs;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * E-90: while a super admin views as someone, the request runs as that person and every
 * write (POST/PUT/PATCH/DELETE) is refused, except stopping and signing out. Ends on its
 * own after 30 minutes, or when either account stops qualifying.
 */
class ViewAsUser
{
    /** Writes allowed during view-as: they act as the admin, not the person viewed. */
    private const ADMIN_ROUTES = ['view-as.stop', 'logout'];

    public function __construct(private readonly ViewAs $viewAs) {}

    public function handle(Request $request, Closure $next): Response
    {
        $state = $this->viewAs->state($request);
        $admin = $request->user();
        if ($state === null || ! $admin instanceof User) {
            return $next($request);
        }
        if ($request->routeIs(...self::ADMIN_ROUTES)) {
            return $next($request);
        }

        $target = User::query()->find($state['target_id']);
        $ended = match (true) {
            $admin->id !== $state['admin_id'] || ! $admin->isSuperAdmin() => 'no longer allowed',
            CarbonImmutable::parse($state['expires_at'])->isPast() => 'expired',
            $target === null || ! $target->is_active || $target->isSuperAdmin() => 'account changed',
            default => null,
        };
        if ($ended !== null) {
            $this->viewAs->end($request, $admin, $ended);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('View as ended (:why). You are yourself again.', ['why' => $ended])]);

            return $next($request);
        }

        if (! $request->isMethodSafe()) {
            $message = __('Read-only: you are viewing as :name. Stop viewing as to make changes.', ['name' => $target->name]);
            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 403);
            }
            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back(303)->withErrors(['view_as' => $message]);
        }

        Auth::guard('web')->setUser($target);
        $request->setUserResolver(fn () => $target);
        $request->attributes->set('view_as', true);
        ViewAs::$readOnly = true;
        try {
            return $next($request);
        } finally {
            // The session still belongs to the admin; put the guard back for anything after this request.
            ViewAs::$readOnly = false;
            Auth::guard('web')->setUser($admin);
        }
    }
}
