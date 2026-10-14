<?php

namespace App\Console\Commands;

use App\Domain\Identity\Permissions;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Domain\Settings\AppSettings;
use App\Models\Holiday;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * E-95: in December, remind the people who keep the holiday calendar to add next year's
 * proclamation, once per year, and only if next year still looks empty.
 */
class RemindHolidays extends Command
{
    /** @var string */
    protected $signature = 'holidays:remind';

    /** @var string */
    protected $description = "Remind admins in December to add next year's holidays";

    /** Fewer than this many holidays next year counts as "not added yet". */
    private const ENOUGH = 10;

    public function handle(Notifier $notifier, AppSettings $settings): int
    {
        $next = now()->year + 1;
        if (now()->month !== 12 || (int) $settings->get('holiday_reminder_year', 0) >= $next) {
            $this->info('Nothing to do.');

            return self::SUCCESS;
        }
        $count = Holiday::query()->whereYear('date', $next)->count();
        if ($count < self::ENOUGH) {
            $ids = User::query()->active()->get()->filter(fn (User $u) => $u->can(Permissions::SYSTEM_HOLIDAYS))->pluck('id');
            $notifier->sendPersonal($ids, NotificationKind::System, [
                'title' => __('Add the :y holidays', ['y' => $next]),
                'body' => __('The calendar has :c holidays for :y. Paste the proclamation list on the Holidays page.', ['c' => $count, 'y' => $next]),
                'url' => route('admin.holidays.index', ['year' => $next], false),
            ]);
        }
        $settings->set('holiday_reminder_year', $next);
        $this->info("Reminder done for {$next}.");

        return self::SUCCESS;
    }
}
