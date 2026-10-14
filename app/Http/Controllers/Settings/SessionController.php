<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Identity\SessionTracker;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * E-88: people end their own sessions from Settings > Security.
 */
class SessionController extends Controller
{
    public function __construct(private readonly SessionTracker $tracker) {}

    public function destroy(Request $request, UserSession $userSession): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($userSession->user_id === $user->id, 404);
        abort_if($userSession->session_id === $request->session()->getId(), 422, 'Use Log out to end this session.');

        $this->tracker->revoke($userSession, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('That device is signed out.')]);

        return back();
    }

    public function destroyOthers(Request $request, ActivityLogger $activity): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $count = $this->tracker->revokeAll($user, $request->session()->getId());
        $activity->log('user.other-sessions-revoked', $user, ['count' => $count], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} No other devices were signed in.|{1} 1 other device signed out.|[2,*] :count other devices signed out.', $count, ['count' => $count])]);

        return back();
    }
}
