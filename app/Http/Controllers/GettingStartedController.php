<?php

namespace App\Http\Controllers;

use App\Domain\Onboarding\GettingStarted;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * E-59: the getting-started checklist (Help in the user menu), hide and show again.
 */
class GettingStartedController extends Controller
{
    public function __construct(private readonly GettingStarted $guide) {}

    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $steps = $this->guide->steps($user);

        return Inertia::render('GettingStarted', [
            'steps' => $steps,
            'hidden' => GettingStarted::hidden($user),
        ]);
    }

    public function hide(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->guide->hide($user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Hidden. Reopen it any time from your menu: Getting started.')]);

        return back();
    }

    public function reopen(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->guide->reopen($user);

        return to_route('dashboard');
    }
}
