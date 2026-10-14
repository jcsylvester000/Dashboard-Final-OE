<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationPreferences;
use App\Domain\Notifications\QuietHours;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Each member picks realtime / digest / off per alert type.
 */
class NotificationSettingsController extends Controller
{
    public function __construct(private readonly NotificationPreferences $preferences) {}

    public function edit(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('settings/Notifications', [
            'kinds' => array_map(fn (NotificationKind $k): array => [
                'value' => $k->value,
                'label' => $k->label(),
                'default' => $k->defaultMode(),
            ], NotificationKind::cases()),
            'modes' => $this->preferences->all($user),
            // E-58
            'quiet' => QuietHours::settings($user),
            'focusUntil' => $user->focus_until !== null && $user->focus_until->isFuture() ? $user->focus_until->toIso8601String() : null,
        ]);
    }

    /** E-58: daily focus window and holding alerts outside working hours. */
    public function quiet(Request $request): RedirectResponse
    {
        $time = ['nullable', 'date_format:H:i'];
        $data = $request->validate([
            'focus_from' => $time, 'focus_to' => [...$time, 'required_with:focus_from'],
            'focus_days' => ['array'], 'focus_days.*' => ['integer', 'between:1,7'],
            'after_hours' => ['boolean'],
            'work_from' => ['required', 'date_format:H:i'], 'work_to' => ['required', 'date_format:H:i'],
            'work_days' => ['array'], 'work_days.*' => ['integer', 'between:1,7'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['quiet_settings' => [
            'focus_from' => $data['focus_from'] ?? null, 'focus_to' => $data['focus_to'] ?? null,
            'focus_days' => array_values(array_map('intval', $data['focus_days'] ?? [])),
            'after_hours' => (bool) ($data['after_hours'] ?? false),
            'work_from' => $data['work_from'], 'work_to' => $data['work_to'],
            'work_days' => array_values(array_map('intval', $data['work_days'] ?? [])),
        ]])->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Quiet hours saved.')]);

        return back();
    }

    /** E-58: focus now for a while (no pop-ups; alerts wait in the digest), or stop. */
    public function focus(Request $request): RedirectResponse
    {
        $for = (string) $request->validate(['for' => ['required', Rule::in(['off', '30m', '1h', '2h', 'today'])]])['for'];
        /** @var User $user */
        $user = $request->user();
        $until = match ($for) {
            '30m' => now()->addMinutes(30),
            '1h' => now()->addHour(),
            '2h' => now()->addHours(2),
            'today' => now()->endOfDay(),
            default => null,
        };
        $user->forceFill(['focus_until' => $until])->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => $until === null ? __('Focus off. Alerts pop up again.') : __('Focusing until :t. Alerts wait in your digest.', ['t' => $until->format('g:i A')])]);

        return back();
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (NotificationKind::cases() as $kind) {
            // Missing kinds keep their default, so adding a new alert type never breaks an open form.
            $rules['modes.'.$kind->value] = ['sometimes', Rule::in(NotificationKind::MODES)];
        }
        $data = $request->validate(['modes' => ['required', 'array'], ...$rules]);

        /** @var User $user */
        $user = $request->user();
        $this->preferences->save($user, $data['modes']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification settings saved.')]);

        return to_route('notifications.edit');
    }
}
