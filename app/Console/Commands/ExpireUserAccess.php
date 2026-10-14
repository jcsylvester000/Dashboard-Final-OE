<?php

namespace App\Console\Commands;

use App\Domain\Identity\Permissions;
use App\Domain\Identity\UserAccessManager;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Models\Department;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * E-93, daily: a week before someone's planned last day, tell their department lead (or
 * the people admins) to hand over their work; the day after the last day, deactivate
 * the account (sessions, links and API tokens end too).
 */
class ExpireUserAccess extends Command
{
    /** @var string */
    protected $signature = 'users:expire-access';

    /** @var string */
    protected $description = 'Warn leads a week before planned access ends, then deactivate';

    public const WARN_DAYS = 7;

    public function handle(Notifier $notifier, UserAccessManager $access): int
    {
        $today = now()->toDateString();
        $warned = 0;
        $ended = 0;

        User::query()->active()->whereNotNull('access_ends_on')->whereNull('access_end_alerted_at')
            ->whereDate('access_ends_on', '>=', $today)
            ->whereDate('access_ends_on', '<=', now()->addDays(self::WARN_DAYS)->toDateString())
            ->get()
            ->each(function (User $user) use ($notifier, &$warned) {
                $lead = $user->primary_department_id !== null
                    ? Department::query()->whereKey($user->primary_department_id)->value('lead_user_id') : null;
                $to = $lead !== null && (int) $lead !== $user->id
                    ? [(int) $lead]
                    : User::query()->active()->get()->filter(fn (User $u) => $u->can(Permissions::USERS_MANAGE) && $u->isNot($user))->pluck('id')->all();
                $notifier->sendPersonal($to, NotificationKind::System, [
                    'title' => __(":n's access ends :d", ['n' => $user->name, 'd' => $user->access_ends_on?->format('M j')]),
                    'body' => __('Reassign their open tasks and projects before then. The account is deactivated the next day.'),
                    'url' => route('admin.users.edit', $user, false),
                ]);
                $user->forceFill(['access_end_alerted_at' => now()])->saveQuietly();
                $warned++;
            });

        User::query()->active()->whereNotNull('access_ends_on')->whereDate('access_ends_on', '<', $today)->get()
            ->each(function (User $user) use ($access, &$ended) {
                $access->deactivate($user, null);
                $ended++;
            });

        $this->info("Warned: {$warned}; deactivated: {$ended}");

        return self::SUCCESS;
    }
}
