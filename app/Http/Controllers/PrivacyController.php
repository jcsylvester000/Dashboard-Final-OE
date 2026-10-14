<?php

namespace App\Http\Controllers;

use App\Domain\Privacy\PrivacyProgram;
use App\Models\PrivacyAcknowledgement;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * E-99: what every team member can see: the staff privacy notice (and acknowledge it),
 * the DPO contact and the data retention schedule.
 */
class PrivacyController extends Controller
{
    public function __construct(private readonly PrivacyProgram $privacy) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $notice = $this->privacy->get();

        return Inertia::render('privacy/Index', [
            'notice' => $notice,
            'acknowledgedAt' => PrivacyAcknowledgement::query()->where('user_id', $user->id)->where('version', $notice['version'])->value('acknowledged_at'),
            'retention' => $this->privacy->retentionSchedule(),
        ]);
    }

    public function acknowledge(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:1']])['version'];
        if ($version !== $this->privacy->get()['version']) {
            throw ValidationException::withMessages(['version' => __('A newer version of the notice was published. Please read it again.')]);
        }
        $this->privacy->acknowledge($user, $version);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Thanks. Your acknowledgement is recorded.')]);

        return back();
    }
}
