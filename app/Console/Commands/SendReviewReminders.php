<?php

namespace App\Console\Commands;

use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Domain\People\Capacity;
use App\Domain\Work\ReviewService;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * E-33: reviews that sit too long. After the project's review time (working days,
 * weekends and PH holidays skipped) the pending reviewers get one reminder; one working
 * day later the client's leads get one escalation. Each alert fires once per round.
 */
class SendReviewReminders extends Command
{
    protected $signature = 'work:review-reminders';

    protected $description = 'Remind reviewers about overdue reviews and escalate to leads';

    public function handle(Notifier $notifier, ReviewService $reviews, Capacity $capacity): int
    {
        $today = now()->toDateString();
        $sent = ['reminder' => 0, 'escalation' => 0];

        Task::query()
            ->where('status_id', TaskStatus::idFor('in-review'))
            ->whereNotNull('review_requested_at')
            ->with('workspace')
            ->chunkById(200, function ($tasks) use ($notifier, $reviews, $capacity, $today, &$sent) {
                foreach ($tasks as $task) {
                    $workspace = $task->workspace;
                    if (! $workspace instanceof Workspace || $workspace->status === 'archived' || $task->review_requested_at === null) {
                        continue;
                    }
                    $started = $task->review_requested_at->toDateString();
                    // Working days that have fully passed since the request (the request day doesn't count).
                    $elapsed = max(0, count($capacity->load($started, $today)->workingDays($started, $today)) - 1);
                    // E-40: a template step can set the task's own review SLA.
                    $sla = $task->review_sla_days !== null ? max(1, $task->review_sla_days)
                        : ($task->project_id !== null ? max(1, (int) Project::query()->whereKey($task->project_id)->value('review_sla_days')) : 1);
                    if ($elapsed < $sla) {
                        continue;
                    }

                    $pending = $reviews->pendingUserIds($task);
                    if ($this->claim($task, 'review_due', $started)) {
                        $to = $pending !== [] ? $pending : $this->leads($workspace);
                        $notifier->send($to, NotificationKind::Review, $workspace, [
                            'title' => __('Review waiting :n working day(s)', ['n' => $elapsed]),
                            'body' => $task->title,
                            'url' => $notifier->taskUrl($task, $workspace),
                            'task_id' => $task->id,
                        ]);
                        $sent['reminder']++;
                    }
                    if ($elapsed >= $sla + 1 && $this->claim($task, 'review_escalated', $started)) {
                        $notifier->send($this->leads($workspace), NotificationKind::Escalation, $workspace, [
                            'title' => __('Review overdue: :task', ['task' => $task->title]),
                            'body' => __('Waiting :n working days for a decision.', ['n' => $elapsed]),
                            'url' => $notifier->taskUrl($task, $workspace),
                            'task_id' => $task->id,
                        ]);
                        $sent['escalation']++;
                    }
                }
            });

        $this->info(sprintf('Review reminders: %d, escalations: %d.', $sent['reminder'], $sent['escalation']));

        return self::SUCCESS;
    }

    private function claim(Task $task, string $kind, string $round): bool
    {
        return DB::table('task_alerts')->insertOrIgnore([
            'task_id' => $task->id, 'kind' => $kind, 'due_on' => $round, 'created_at' => now(),
        ]) === 1;
    }

    /**
     * @return list<int>
     */
    private function leads(Workspace $workspace): array
    {
        return DB::table('workspace_user')->where('workspace_id', $workspace->id)
            ->whereIn('role', [Workspace::ROLE_OWNER, Workspace::ROLE_LEAD])
            ->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
