<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Identity\SessionTracker;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class SecurityController extends Controller
{
    /**
     * Password, signed-in devices and recent sign-ins (E-88). Sign-in is email and
     * password only.
     */
    public function edit(Request $request, SessionTracker $sessions): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/Security', [
            'devices' => $sessions->devices($user, $request->session()->getId()),
            'loginHistory' => $sessions->history($user),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Update the user's password.
     */
    public function update(PasswordUpdateRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->password,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Password updated.')]);

        return back();
    }
}
