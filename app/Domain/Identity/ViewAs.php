<?php

namespace App\Domain\Identity;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * E-90: a super admin sees the app exactly as one team member sees it, read-only, for
 * 30 minutes at most, with a reason. Never for another super admin. Start and end are
 * in the activity log. While it runs every write is refused (ViewAsUser middleware) and
 * model saves are dropped as a second line of defence.
 */
class ViewAs
{
    public const SESSION_KEY = 'view_as';

    public const MINUTES = 30;

    /** True while a view-as request is being handled (checked by the model-save guard). */
    public static bool $readOnly = false;

    public function __construct(private readonly ActivityLogger $activity) {}

    public static function active(): bool
    {
        return self::$readOnly;
    }

    public function start(Request $request, User $admin, User $target, string $reason): void
    {
        $why = match (true) {
            ! $admin->isSuperAdmin() => __('Only a Super Admin can view as someone.'),
            $admin->is($target) => __('You are already you.'),
            $target->isSuperAdmin() => __('Super Admins can\'t be viewed as.'),
            ! $target->is_active => __('Reactivate the account first.'),
            default => null,
        };
        if ($why !== null) {
            throw ValidationException::withMessages(['view_as' => $why]);
        }

        $now = CarbonImmutable::now();
        $request->session()->put(self::SESSION_KEY, [
            'target_id' => $target->id,
            'admin_id' => $admin->id,
            'reason' => $reason,
            'started_at' => $now->toIso8601String(),
            'expires_at' => $now->addMinutes(self::MINUTES)->toIso8601String(),
        ]);
        $this->activity->log('user.view-as-started', $target, ['reason' => $reason, 'minutes' => self::MINUTES], $admin);
    }

    /**
     * The view-as state in this session, or null.
     *
     * @return array{target_id: int, admin_id: int, reason: string, started_at: string, expires_at: string}|null
     */
    public function state(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }
        $state = $request->session()->get(self::SESSION_KEY);
        if (! is_array($state) || ! isset($state['target_id'], $state['admin_id'], $state['expires_at'])) {
            return null;
        }

        return [
            'target_id' => (int) $state['target_id'],
            'admin_id' => (int) $state['admin_id'],
            'reason' => (string) ($state['reason'] ?? ''),
            'started_at' => (string) ($state['started_at'] ?? ''),
            'expires_at' => (string) $state['expires_at'],
        ];
    }

    public function end(Request $request, User $admin, string $how): void
    {
        $state = $this->state($request);
        $request->session()->forget(self::SESSION_KEY);
        if ($state !== null) {
            $target = User::query()->find($state['target_id']);
            $this->activity->log('user.view-as-ended', $target, ['how' => $how, 'started_at' => $state['started_at']], $admin);
        }
    }
}
