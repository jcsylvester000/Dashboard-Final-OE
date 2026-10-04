<?php

namespace App\Console\Commands;

use App\Domain\Work\TaskPresenter;
use App\Events\Work\TaskDueSoon;
use App\Events\Work\TaskOverdue;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Hourly (routes/console.php): due-soon, overdue and escalation alerts.
 * task_alerts makes each alert fire once per task and due date, so running
 * it again (or every hour) never repeats an alert; moving the due date re-arms it.
 */
class SendTaskAlerts extends Command
{
    /** @var string */
    protected $signature = 'work:alerts';

    /** @var string */
    protected $description = 'Send due-soon, overdue and escalation alerts (in-app only)';

    /** Overdue by more than this many days escalates to the lead. */
    public const ESCALATE_AFTER_DAYS = 2;

    public function handle(TaskPresenter $present): int
    {
        $today = now()->startOfDay();
        $done = $present->doneStatusIds();

        $open = fn () => Task::query()
            ->whereNotNull('due_on')
            ->whereNotIn('status_id', $done)
            ->whereHas('workspace', fn (Builder $q) => $q->where('status', '!=', 'archived'));

        $counts = ['due_soon' => 0, 'overdue' => 0, 'escalation' => 0];

        $open()
            ->whereDate('due_on', '>=', $today->toDateString())
            ->whereDate('due_on', '<=', $today->addDay()->toDateString())
            ->chunkById(200, function ($tasks) use (&$counts) {
                foreach ($tasks as $task) {
                    if ($this->claim($task, 'due_soon')) {
                        TaskDueSoon::dispatch($task);
                        $counts['due_soon']++;
                    }
                }
            });

        $open()
            ->whereDate('due_on', '<', $today->toDateString())
            ->chunkById(200, function ($tasks) use (&$counts, $today) {
                foreach ($tasks as $task) {
                    if ($this->claim($task, 'overdue')) {
                        TaskOverdue::dispatch($task);
                        $counts['overdue']++;
                    }

                    $late = $task->due_on !== null && $task->due_on->lt($today->subDays(self::ESCALATE_AFTER_DAYS));
                    if ($late && $this->claim($task, 'escalation')) {
                        TaskOverdue::dispatch($task, true);
                        $counts['escalation']++;
                    }
                }
            });

        $this->info(sprintf('Alerts sent - due soon: %d, overdue: %d, escalations: %d', ...array_values($counts)));

        return self::SUCCESS;
    }

    /**
     * True the first time this alert is claimed for the task's current due date.
     */
    private function claim(Task $task, string $kind): bool
    {
        return DB::table('task_alerts')->insertOrIgnore([
            'task_id' => $task->id,
            'kind' => $kind,
            'due_on' => $task->due_on?->toDateString(),
            'created_at' => now(),
        ]) === 1;
    }
}
