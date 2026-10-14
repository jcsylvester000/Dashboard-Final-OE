<?php

namespace App\Console\Commands;

use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Domain\People\Capacity;
use App\Models\Department;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * E-39: complete timesheets before billing.
 *  --friday : people who logged under 80% of this week's capacity so far (leave and
 *             PH holidays already taken out) get a nudge.
 *  --monday : people with last week's time not yet submitted get a reminder, and each
 *             department lead gets one list of who still has to submit.
 * Alerts use the Timesheet kind, so everyone's notification preferences apply.
 */
class SendTimesheetNudges extends Command
{
    protected $signature = 'time:nudges {--friday} {--monday}';

    protected $description = 'Friday low-hours nudge and Monday submit reminder';

    public function handle(Notifier $notifier, Capacity $capacity): int
    {
        $today = CarbonImmutable::now();
        if ($this->option('friday')) {
            $this->friday($notifier, $capacity, $today);
        }
        if ($this->option('monday')) {
            $this->monday($notifier, $today);
        }

        return self::SUCCESS;
    }

    private function friday(Notifier $notifier, Capacity $capacity, CarbonImmutable $today): void
    {
        $from = $today->startOfWeek()->toDateString();
        $to = $today->toDateString();
        $users = User::query()->active()->get(['id', 'name']);
        $capacity->load($from, $to, $users->pluck('id')->all());
        $logged = TimeEntry::query()->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to)
            ->selectRaw('user_id, sum(minutes) as m')->groupBy('user_id')->pluck('m', 'user_id');

        $n = 0;
        foreach ($users as $u) {
            $expected = $capacity->minutes($u->id, $from, $to);
            $have = (int) ($logged[$u->id] ?? 0);
            if ($expected > 0 && $have < 0.8 * $expected) {
                $notifier->sendPersonal([$u->id], NotificationKind::Timesheet, [
                    'title' => __('Timesheet check: :h of :e hours this week', ['h' => round($have / 60, 1), 'e' => round($expected / 60, 1)]),
                    'body' => __('Add any missing time before the week closes.'),
                    'url' => route('time.timesheet', [], false),
                ]);
                $n++;
            }
        }
        $this->info("Friday nudges: {$n}.");
    }

    private function monday(Notifier $notifier, CarbonImmutable $today): void
    {
        $start = $today->subWeek()->startOfWeek();
        $end = $start->endOfWeek();
        $missing = TimeEntry::query()
            ->whereDate('entry_date', '>=', $start->toDateString())->whereDate('entry_date', '<=', $end->toDateString())
            ->whereNull('submitted_at')->whereNull('approved_at')
            ->distinct()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $people = User::query()->active()->whereIn('id', $missing)->get(['id', 'name', 'primary_department_id']);

        foreach ($people as $u) {
            $notifier->sendPersonal([$u->id], NotificationKind::Timesheet, [
                'title' => __('Submit last week\'s timesheet'),
                'body' => __('Week of :d is not submitted yet.', ['d' => $start->format('M j')]),
                'url' => route('time.timesheet', ['week' => $start->toDateString()], false),
            ]);
        }

        foreach ($people->groupBy('primary_department_id') as $deptId => $group) {
            $lead = Department::query()->whereKey((int) $deptId)->value('lead_user_id');
            if ($lead !== null) {
                $notifier->sendPersonal([(int) $lead], NotificationKind::Timesheet, [
                    'title' => __(':n in your team haven\'t submitted last week', ['n' => $group->count()]),
                    'body' => $group->pluck('name')->implode(', '),
                    'url' => route('time.approvals', [], false),
                ]);
            }
        }
        $this->info('Monday reminders: '.$people->count().'.');
    }
}
