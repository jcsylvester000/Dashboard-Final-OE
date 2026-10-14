<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\ViewAs;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * E-90: start / stop viewing the app as a team member (super admins, read-only, 30 minutes).
 */
class ViewAsController extends Controller
{
    public function __construct(private readonly ViewAs $viewAs) {}

    public function start(Request $request, User $user): RedirectResponse
    {
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'min:5', 'max:255']])['reason'];
        /** @var User $admin */
        $admin = $request->user();
        $this->viewAs->start($request, $admin, $user, $reason);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Viewing as :name, read-only, for up to :m minutes.', ['name' => $user->name, 'm' => ViewAs::MINUTES])]);

        return to_route('dashboard');
    }

    public function stop(Request $request): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $state = $this->viewAs->state($request);
        $this->viewAs->end($request, $admin, 'stopped');
        Inertia::flash('toast', ['type' => 'success', 'message' => __('You are yourself again.')]);

        return $state !== null ? to_route('admin.users.edit', $state['target_id']) : to_route('dashboard');
    }
}
