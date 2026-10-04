<?php

namespace App\Http\Controllers\Auth;

use App\Concerns\PasswordValidationRules;
use App\Domain\Identity\AccessLinkService;
use App\Http\Controllers\Controller;
use App\Models\UserAccessLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Redeems an admin-issued one-time link: account setup or password reset.
 */
class AccessLinkController extends Controller
{
    use PasswordValidationRules;

    public function __construct(private readonly AccessLinkService $links) {}

    public function show(Request $request, string $token): Response
    {
        $link = $this->links->find($token);

        if ($link === null) {
            return Inertia::render('auth/AccessLink', ['valid' => false]);
        }

        return Inertia::render('auth/AccessLink', [
            'valid' => true,
            'token' => $token,
            'purpose' => $link->purpose,
            'name' => $link->user->name,
            'email' => $link->user->email,
            'expiresAt' => $link->expires_at->toIso8601String(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $link = $this->links->find($token);

        if ($link === null) {
            return to_route('access-link.show', ['token' => $token]);
        }

        $validated = $request->validate([
            'password' => $this->passwordRules(),
        ]);

        // Never leave someone else's session attached to this browser.
        if (Auth::check()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        $this->links->redeem($link, $validated['password']);

        $message = $link->purpose === UserAccessLink::PURPOSE_SETUP
            ? __('Your account is ready. Log in with your new password.')
            : __('Your password was reset. Log in with your new password.');

        return to_route('login')->with('status', $message);
    }
}
