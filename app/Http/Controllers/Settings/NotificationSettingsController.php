<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\NotificationPreferences;
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
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $rules = [];
        foreach (NotificationKind::cases() as $kind) {
            $rules['modes.'.$kind->value] = ['required', Rule::in(NotificationKind::MODES)];
        }
        $data = $request->validate(['modes' => ['required', 'array'], ...$rules]);

        /** @var User $user */
        $user = $request->user();
        $this->preferences->save($user, $data['modes']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Notification settings saved.')]);

        return to_route('notifications.edit');
    }
}
