<?php

namespace App\Domain\Work;

use App\Domain\Billing\PeriodService;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Reports\TimeAggregates;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Timers, manual time entries and approvals. Approved (or billed) entries are
 * read-only for their author; this is what invoices in P6 are built from.
 */
class TimeService
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly TaskService $tasks,
    ) {}

    public function running(User $user): ?TimeEntry
    {
        return TimeEntry::query()->where('user_id', $user->id)->running()->latest('id')->first();
    }

    /**
     * Start a timer on a task. Any timer the user already has running is stopped first,
     * so a person only ever has one running timer.
     */
    public function start(Task $task, User $user): TimeEntry
    {
        $this->guardPeriod($task->workspace_id, now()->toDateString());

        $entry = DB::transaction(function () use ($task, $user) {
            $current = $this->running($user);
            if ($current !== null) {
                $this->stop($current);
            }

            $now = now();

            return TimeEntry::create([
                'user_id' => $user->id,
                'workspace_id' => $task->workspace_id,
                'task_id' => $task->id,
                'project_id' => $task->project_id,
                'department_id' => $task->department_id,
                'entry_date' => $now->toDateString(),
                'started_at' => $now,
                'minutes' => 0,
                'is_billable' => true,
            ]);
        });

        $this->markStarted($task, $user);

        return $entry;
    }

    /**
     * Working on a task means it has started: To Do (or Backlog with nothing to
     * wait on) moves to In Progress so the board matches reality.
     */
    private function markStarted(Task $task, User $user): void
    {
        $status = TaskStatus::ordered()->firstWhere('id', $task->status_id);
        if ($status === null || ! in_array($status->slug, ['todo', 'backlog'], true)) {
            return;
        }

        if ($status->slug === 'backlog' && $task->dependencies()->whereNotIn('tasks.status_id', TaskStatus::ordered()->where('category', 'done')->pluck('id'))->exists()) {
            return;
        }

        $this->tasks->update($task, ['status_id' => TaskStatus::idFor('in-progress')], $user);
    }

    /**
     * Stop a running timer. Minutes are rounded up to the next minute (at least 1)
     * and capped at TimeEntry::MAX_MINUTES in case a timer was left on overnight.
     */
    public function stop(TimeEntry $entry): TimeEntry
    {
        if (! $entry->isRunning() || $entry->started_at === null) {
            return $entry;
        }

        $end = now();
        $minutes = (int) max(1, ceil($entry->started_at->diffInSeconds($end) / 60));

        $entry->forceFill([
            'ended_at' => $end,
            'minutes' => min($minutes, TimeEntry::MAX_MINUTES),
        ])->save();

        return $entry;
    }

    /**
     * Manual entry ("I spent 1h30 on this yesterday").
     *
     * @param  array{entry_date: string, minutes: int, note?: string|null, is_billable?: bool}  $data
     */
    public function log(Task $task, User $user, array $data): TimeEntry
    {
        $this->guardPeriod($task->workspace_id, $data['entry_date']);
        $this->activity->log('task.time-logged', $task, ['minutes' => $data['minutes']], $user);

        $entry = TimeEntry::create([
            'user_id' => $user->id,
            'workspace_id' => $task->workspace_id,
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'department_id' => $task->department_id,
            'entry_date' => $data['entry_date'],
            'minutes' => $data['minutes'],
            'note' => $data['note'] ?? null,
            'is_billable' => $data['is_billable'] ?? true,
        ]);

        $this->markStarted($task, $user);

        return $entry;
    }

    /**
     * @param  array{entry_date?: string, minutes?: int, note?: string|null, is_billable?: bool}  $data
     */
    public function update(TimeEntry $entry, array $data): TimeEntry
    {
        $this->guardEditable($entry);
        if (isset($data['entry_date'])) {
            $this->guardPeriod($entry->workspace_id, $data['entry_date']);
        }
        $entry->fill($data)->save();

        return $entry;
    }

    public function delete(TimeEntry $entry): void
    {
        $this->guardEditable($entry);
        $entry->delete();
    }

    /**
     * Approve entries (leads). Running timers and your own entries are skipped.
     *
     * @param  list<int>  $ids  already filtered to entries the approver may approve
     * @return int number approved
     */
    public function approve(array $ids, User $approver): int
    {
        $count = TimeEntry::query()
            ->whereIn('id', $ids)
            ->whereNull('approved_at')
            ->where('user_id', '!=', $approver->id)
            // Manual entries, or timers that have been stopped.
            ->where(fn ($q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'))
            ->update(['approved_by' => $approver->id, 'approved_at' => now(), 'returned_at' => null, 'returned_by' => null, 'return_reason' => null]);

        if ($count > 0) {
            $this->activity->log('time.approved', null, ['count' => $count, 'ids' => array_slice($ids, 0, 50)], $approver);
            app(TimeAggregates::class)->refreshEntries($ids); // E-129: bulk update skips model events
        }

        return $count;
    }

    /**
     * Submit my week for approval: every finished, unapproved entry in the week
     * is marked submitted (and any earlier "returned" note is cleared).
     */
    public function submitWeek(User $user, CarbonImmutable $weekStart): int
    {
        $count = TimeEntry::query()
            ->where('user_id', $user->id)
            ->whereDate('entry_date', '>=', $weekStart->toDateString())
            ->whereDate('entry_date', '<=', $weekStart->addDays(6)->toDateString())
            ->whereNull('approved_at')
            ->where(fn ($q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'))
            ->update(['submitted_at' => now(), 'returned_at' => null, 'returned_by' => null, 'return_reason' => null]);

        if ($count > 0) {
            $this->activity->log('time.submitted', null, ['week' => $weekStart->toDateString(), 'count' => $count], $user);
        }

        return $count;
    }

    /**
     * Send entries back to their author with a reason (approvers only; never your own).
     *
     * @param  list<int>  $ids  already filtered to entries the approver may approve
     * @return Collection<int, TimeEntry> the returned entries
     */
    public function returnEntries(array $ids, User $approver, string $reason): Collection
    {
        $entries = TimeEntry::query()->with('workspace')
            ->whereIn('id', $ids)
            ->whereNull('approved_at')
            ->where('user_id', '!=', $approver->id)
            ->get();

        foreach ($entries as $entry) {
            $entry->forceFill([
                'submitted_at' => null,
                'returned_at' => now(),
                'returned_by' => $approver->id,
                'return_reason' => $reason,
            ])->save();
        }

        if ($entries->isNotEmpty()) {
            $this->activity->log('time.returned', null, ['count' => $entries->count(), 'reason' => $reason], $approver);
        }

        return $entries;
    }

    /**
     * Undo an approval (only while the entry has not been billed).
     *
     * @param  list<int>  $ids
     */
    public function unapprove(array $ids, User $approver): int
    {
        $count = TimeEntry::query()
            ->whereIn('id', $ids)
            ->whereNotNull('approved_at')
            ->whereNull('locked_at')
            ->where('user_id', '!=', $approver->id)
            // Not inside a locked billing period: a draft invoice may already bill it.
            ->whereNotExists(fn ($q) => $q->from('billing_periods')
                ->whereColumn('billing_periods.workspace_id', 'time_entries.workspace_id')
                ->where('billing_periods.status', '!=', 'open')
                ->whereColumn('billing_periods.starts_on', '<=', 'time_entries.entry_date')
                ->whereColumn('billing_periods.ends_on', '>=', 'time_entries.entry_date'))
            ->update(['approved_by' => null, 'approved_at' => null]);

        if ($count > 0) {
            $this->activity->log('time.unapproved', null, ['count' => $count], $approver);
            app(TimeAggregates::class)->refreshEntries($ids); // E-129
        }

        return $count;
    }

    private function guardEditable(TimeEntry $entry): void
    {
        if ($entry->isLocked()) {
            throw ValidationException::withMessages([
                'entry' => __('This entry is approved or billed and can no longer be changed.'),
            ]);
        }
        if ($entry->submitted_at !== null) {
            throw ValidationException::withMessages([
                'entry' => __('This entry is submitted and waiting for approval. Ask your lead to return it if it needs a change.'),
            ]);
        }
        $this->guardPeriod($entry->workspace_id, $entry->entry_date->toDateString());
    }

    /**
     * No time can be added, changed or removed inside a locked billing period (P6).
     */
    private function guardPeriod(int $workspaceId, string $date): void
    {
        if (PeriodService::isLocked($workspaceId, substr($date, 0, 10))) {
            throw ValidationException::withMessages([
                'entry' => __('This date is in a locked billing period. Ask Finance to unlock it.'),
            ]);
        }
    }

    /**
     * Monday of the week containing $date.
     */
    public static function weekStart(?string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date ?? 'now')->startOfWeek(CarbonInterface::MONDAY)->startOfDay();
    }
}
