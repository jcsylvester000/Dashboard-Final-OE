<?php

namespace App\Domain\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * E-58: when a person doesn't want live alerts. During focus (a "focus now" timer or a daily
 * focus window) and, if they chose it, outside their working hours, alerts still arrive but
 * quietly: no pop-up, they wait in the digest. Times are the app's timezone (Manila).
 */
class QuietHours
{
    /**
     * @return array{focus_days: list<int>, focus_from: string|null, focus_to: string|null, after_hours: bool, work_days: list<int>, work_from: string, work_to: string}
     */
    public static function settings(User $user): array
    {
        $s = is_array($user->quiet_settings) ? $user->quiet_settings : [];
        $days = fn ($v, array $default): array => is_array($v) ? array_values(array_map('intval', array_filter($v, 'is_numeric'))) : $default;
        $time = fn ($v): ?string => is_string($v) && preg_match('/^\d{2}:\d{2}$/', $v) === 1 ? $v : null;

        return [
            'focus_days' => $days($s['focus_days'] ?? null, [1, 2, 3, 4, 5]),
            'focus_from' => $time($s['focus_from'] ?? null),
            'focus_to' => $time($s['focus_to'] ?? null),
            'after_hours' => (bool) ($s['after_hours'] ?? false),
            'work_days' => $days($s['work_days'] ?? null, [1, 2, 3, 4, 5]),
            'work_from' => $time($s['work_from'] ?? null) ?? '08:00',
            'work_to' => $time($s['work_to'] ?? null) ?? '18:00',
        ];
    }

    public function isQuiet(User $user, ?CarbonImmutable $at = null): bool
    {
        $at ??= CarbonImmutable::now();
        if ($user->focus_until !== null && $user->focus_until->gt($at)) {
            return true;
        }
        $s = self::settings($user);
        $day = (int) $at->format('N'); // 1 = Monday
        $now = $at->format('H:i');

        if ($s['focus_from'] !== null && $s['focus_to'] !== null && in_array($day, $s['focus_days'], true)
            && self::within($now, $s['focus_from'], $s['focus_to'])) {
            return true;
        }

        return $s['after_hours'] && (! in_array($day, $s['work_days'], true) || ! self::within($now, $s['work_from'], $s['work_to']));
    }

    /** Until when this person snoozed alerts about this task (null = not snoozed). */
    public function mutedUntil(User $user, ?int $taskId): ?CarbonImmutable
    {
        if ($taskId === null) {
            return null;
        }
        $until = DB::table('task_mutes')->where('user_id', $user->id)->where('task_id', $taskId)->where('until', '>', now())->value('until');

        return $until !== null ? CarbonImmutable::parse((string) $until) : null;
    }

    /** "HH:MM" inside [from, to); a window like 22:00-06:00 wraps past midnight. */
    private static function within(string $now, string $from, string $to): bool
    {
        return $from <= $to ? ($now >= $from && $now < $to) : ($now >= $from || $now < $to);
    }
}
