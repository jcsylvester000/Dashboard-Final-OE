<?php

namespace App\Domain\Notifications;

use App\Models\User;

/**
 * Per-user delivery mode for each notification kind (users.notification_preferences JSON).
 */
class NotificationPreferences
{
    public function mode(User $user, NotificationKind $kind): string
    {
        $saved = $user->notification_preferences[$kind->value] ?? null;

        return in_array($saved, NotificationKind::MODES, true) ? $saved : $kind->defaultMode();
    }

    /**
     * @return array<string, string> kind => mode
     */
    public function all(User $user): array
    {
        $modes = [];
        foreach (NotificationKind::cases() as $kind) {
            $modes[$kind->value] = $this->mode($user, $kind);
        }

        return $modes;
    }

    /**
     * @param  array<string, string>  $modes
     */
    public function save(User $user, array $modes): void
    {
        $clean = [];
        foreach (NotificationKind::cases() as $kind) {
            $mode = $modes[$kind->value] ?? null;
            if (in_array($mode, NotificationKind::MODES, true)) {
                $clean[$kind->value] = $mode;
            }
        }

        $user->forceFill(['notification_preferences' => $clean])->save();
    }
}
