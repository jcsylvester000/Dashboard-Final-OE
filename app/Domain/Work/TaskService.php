<?php

namespace App\Domain\Work;

use App\Domain\Billing\PeriodService;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Workspaces\MentionService;
use App\Events\Work\TaskAssigned;
use App\Events\Work\TaskStatusChanged;
use App\Models\Attachment;
use App\Models\Department;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Creates and changes tasks, keeping the timeline (activity log), watchers,
 * mentions and the "can't finish while waiting on others" rule consistent.
 */
class TaskService
{
    /** Fields whose changes appear on the task timeline. */
    private const TRACKED = ['title', 'status_id', 'assignee_id', 'department_id', 'project_id', 'due_on', 'priority', 'type', 'sprint_id', 'story_points', 'start_on', 'recurrence', 'recurrence_until'];

    /** @var array{moved: int, kept: int}|null set by the last update that changed the project */
    public ?array $lastTimeMove = null;

    public function __construct(
        private readonly MentionService $mentions,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated input
     */
    public function create(Workspace $workspace, array $data, User $actor): Task
    {
        return DB::transaction(function () use ($workspace, $data, $actor) {
            $normalized = $this->mentions->normalize($data['description'] ?? null, $workspace->id);
            $statusId = (int) ($data['status_id'] ?? TaskStatus::idFor('todo'));

            $task = new Task;
            $task->fill([
                ...Arr::only($data, ['project_id', 'department_id', 'parent_id', 'title', 'type', 'priority', 'assignee_id', 'due_on', 'start_on', 'estimate_minutes', 'work_details', 'sprint_id', 'story_points', 'recurrence', 'recurrence_until', 'custom_values', 'bug_report']),
                'workspace_id' => $workspace->id,
                'status_id' => $statusId,
                'description' => $normalized['text'],
                'reporter_id' => $actor->id,
                'priority' => $data['priority'] ?? 'normal',
                'position' => $this->nextPosition($workspace->id, $statusId),
            ]);
            $this->stampCompletion($task);
            $task->save();

            // E-36: the client's checklist template for this task type.
            foreach ($workspace->checklist_templates[$task->type] ?? [] as $i => $line) {
                if (trim($line) !== '') {
                    $task->checklistItems()->create(['label' => mb_substr(trim($line), 0, 200), 'position' => $i]);
                }
            }

            if (array_key_exists('label_ids', $data)) {
                $task->labels()->sync(array_map('intval', (array) $data['label_ids']));
            }
            $this->watch($task, [$actor->id, $task->assignee_id]);
            $this->mentions->sync($task, $normalized['user_ids'], $actor);

            $this->activity->log('task.created', $task, ['workspace_id' => $workspace->id], $actor);

            if ($task->assignee_id !== null) {
                TaskAssigned::dispatch($task, $actor);
            }

            return $task;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated input (only keys present are changed)
     */
    public function update(Task $task, array $data, User $actor): Task
    {
        $this->lastTimeMove = null;

        return DB::transaction(function () use ($task, $data, $actor) {
            $before = Arr::only($task->getAttributes(), self::TRACKED);

            $fill = Arr::only($data, ['project_id', 'department_id', 'status_id', 'parent_id', 'title', 'type', 'priority', 'assignee_id', 'due_on', 'start_on', 'estimate_minutes', 'position', 'work_details', 'sprint_id', 'story_points', 'recurrence', 'recurrence_until', 'custom_values', 'bug_report']);

            $mentionIds = null;
            if (array_key_exists('description', $data)) {
                $normalized = $this->mentions->normalize($data['description'], $task->workspace_id);
                $fill['description'] = $normalized['text'];
                $mentionIds = $normalized['user_ids'];
            }

            $task->fill($fill);

            $newRound = false;
            if ($task->isDirty('status_id')) {
                $this->guardFinish($task);
                $this->guardReview($task, $actor);
                $this->guardReviewers($task, $actor);
                $this->stampCompletion($task);
                // E-33 / E-07: entering In Review starts a new round and the review clock.
                if ($task->status_id === TaskStatus::idFor('in-review')) {
                    $task->forceFill(['review_requested_at' => now()]);
                    $newRound = true;
                }
            }

            $task->save();
            if ($newRound) {
                app(ReviewService::class)->resetRound($task);
            }

            if (array_key_exists('label_ids', $data)) {
                $task->labels()->sync(array_map('intval', (array) $data['label_ids']));
            }
            if ($task->wasChanged('assignee_id')) {
                $this->watch($task, [$task->assignee_id]);
            }
            if ($mentionIds !== null) {
                $this->mentions->sync($task, $mentionIds, $actor);
            }

            if ($task->wasChanged('project_id')) {
                $this->moveOpenTime($task, $actor);
            }

            $changes = $this->describeChanges($before, Arr::only($task->getAttributes(), self::TRACKED));
            if ($changes !== []) {
                $this->activity->log('task.updated', $task, ['changes' => $changes], $actor);
            }

            if ($task->wasChanged('assignee_id') && $task->assignee_id !== null) {
                TaskAssigned::dispatch($task, $actor);
            }
            if ($task->wasChanged('status_id')) {
                TaskStatusChanged::dispatch($task, (int) $before['status_id'], $actor);
            }

            if ($task->wasChanged('status_id') && $task->completed_at !== null) {
                $this->advanceDependents($task, $actor);
                $this->repeat($task, $actor);
            }

            return $task;
        });
    }

    /**
     * Board drag & drop: new status and position in that column.
     */
    public function move(Task $task, int $statusId, int $position, User $actor): Task
    {
        return $this->update($task, ['status_id' => $statusId, 'position' => $position], $actor);
    }

    /**
     * @param  list<int|null>  $userIds
     */
    public function watch(Task $task, array $userIds): void
    {
        $ids = array_values(array_unique(array_filter($userIds)));
        if ($ids !== []) {
            $task->watchers()->syncWithoutDetaching($ids);
        }
    }

    /**
     * When a task is finished, waiting tasks that are still in Backlog or Blocked and
     * have nothing else open move to To Do, and their assignee gets "Ready to start".
     */
    private function advanceDependents(Task $task, User $actor): void
    {
        $waitingIds = [TaskStatus::idFor('backlog'), TaskStatus::idFor('blocked')];
        $todoId = TaskStatus::idFor('todo');

        $waiting = $task->dependents()->whereIn('tasks.status_id', $waitingIds)->get();
        foreach ($waiting as $next) {
            if ($next->openDependencyCount() === 0) {
                $from = (int) $next->status_id;
                $next->forceFill(['status_id' => $todoId])->save();
                $this->activity->log('task.unblocked', $next, ['after_task_id' => $task->id], $actor);
                TaskStatusChanged::dispatch($next, $from, $actor, $task);
            }
        }
    }

    /**
     * A task cannot be finished while it still waits on unfinished tasks.
     */
    private function guardFinish(Task $task): void
    {
        $status = TaskStatus::ordered()->firstWhere('id', $task->status_id);
        if ($status === null || ! $status->isDone()) {
            return;
        }

        // E-36: required checklist lines must be ticked.
        $openItems = $task->exists ? $task->checklistItems()->where('required', true)->whereNull('done_at')->count() : 0;
        if ($openItems > 0) {
            throw ValidationException::withMessages([
                'status_id' => trans_choice(':count required checklist item is still open.|:count required checklist items are still open.', $openItems),
            ]);
        }

        $open = $task->exists ? $task->openDependencyCount() : 0;
        if ($open > 0) {
            throw ValidationException::withMessages([
                'status_id' => trans_choice(
                    'Still waiting on :count task. Finish it first or remove the dependency.|Still waiting on :count tasks. Finish them first or remove the dependencies.',
                    $open,
                ),
            ]);
        }
    }

    /**
     * S2-13: in projects that need sign-off, only a reviewer (client, project or
     * department lead) can mark work Done. Everyone else moves it to In Review.
     */
    private function guardReview(Task $task, User $actor): void
    {
        $status = TaskStatus::ordered()->firstWhere('id', $task->status_id);
        if ($status === null || ! $status->isDone() || $task->project_id === null) {
            return;
        }
        if (! Project::query()->whereKey($task->project_id)->value('review_required')) {
            return;
        }
        // E-06: every deliverable's latest version must be approved first (reviewers too).
        $pending = $task->exists ? Attachment::query()
            ->where('attachable_type', $task->getMorphClass())->where('attachable_id', $task->id)
            ->where('is_latest', true)->whereNull('approved_at')->count() : 0;
        if ($pending > 0) {
            throw ValidationException::withMessages([
                'status_id' => trans_choice(':count file still needs approval (latest version) before Done.|:count files still need approval (latest versions) before Done.', $pending),
            ]);
        }
        if (Gate::forUser($actor)->allows('review', $task)) {
            return;
        }

        throw ValidationException::withMessages([
            'status_id' => __('This project needs a lead\'s sign-off. Move the task to In Review and a lead will approve it.'),
        ]);
    }

    /**
     * B-04: finishing a recurring task creates the next one (once), shifted by the interval.
     * Stops after recurrence_until. Labels, owner, estimate and project carry over.
     */
    public function repeat(Task $task, User $actor): ?Task
    {
        if ($task->recurrence === null || $task->due_on === null
            || Task::query()->where('recurred_from_task_id', $task->id)->exists()) {
            return null;
        }

        $shift = fn (CarbonInterface $d): CarbonInterface => match ($task->recurrence) {
            'weekly' => $d->copy()->addWeek(),
            'biweekly' => $d->copy()->addWeeks(2),
            default => $d->copy()->addMonthNoOverflow(),
        };
        $due = $shift($task->due_on);
        if ($task->recurrence_until !== null && $due->toDateString() > $task->recurrence_until->toDateString()) {
            return null;
        }

        $workspace = Workspace::query()->findOrFail($task->workspace_id);
        $next = $this->create($workspace, [
            'title' => $this->nextTitle($task->title, $task->due_on, $due),
            'description' => $task->description,
            'project_id' => $task->project_id,
            'department_id' => $task->department_id,
            'assignee_id' => $task->assignee_id,
            'priority' => $task->priority,
            'type' => $task->type,
            'estimate_minutes' => $task->estimate_minutes,
            'work_details' => $task->work_details,
            'due_on' => $due->toDateString(),
            'start_on' => $task->start_on !== null ? $shift($task->start_on)->toDateString() : null,
            'recurrence' => $task->recurrence,
            'recurrence_until' => $task->recurrence_until?->toDateString(),
            'label_ids' => $task->labels()->pluck('labels.id')->all(),
        ], $actor);
        $next->forceFill(['recurred_from_task_id' => $task->id])->save();

        return $next;
    }

    /**
     * S3-18: time logged on a task follows it to its new project, unless it is already
     * billed or sits in a locked/invoiced period (those stay where they were invoiced).
     *
     * @return array{moved: int, kept: int}
     */
    public function moveOpenTime(Task $task, User $actor): array
    {
        $entries = TimeEntry::query()->where('task_id', $task->id)->where('workspace_id', $task->workspace_id)->get();
        $moved = 0;
        $kept = 0;
        foreach ($entries as $entry) {
            if ($entry->project_id === $task->project_id) {
                continue;
            }
            if ($entry->locked_at !== null || PeriodService::isLocked($task->workspace_id, $entry->entry_date->toDateString())) {
                $kept += $entry->minutes;

                continue;
            }
            $entry->forceFill(['project_id' => $task->project_id])->save();
            $moved += $entry->minutes;
        }

        if ($moved > 0 || $kept > 0) {
            $this->activity->log('task.time-moved', $task, ['moved_minutes' => $moved, 'kept_minutes' => $kept, 'project_id' => $task->project_id], $actor);
        }
        $this->lastTimeMove = ['moved' => $moved, 'kept' => $kept];

        return $this->lastTimeMove;
    }

    /**
     * E-07: with named reviewers, only their approval (by the rule) or a lead's override reaches Done.
     */
    private function guardReviewers(Task $task, User $actor): void
    {
        $status = TaskStatus::ordered()->firstWhere('id', $task->status_id);
        if ($status === null || ! $status->isDone() || ! $task->exists || ! $task->reviewers()->exists()) {
            return;
        }
        $workspace = Workspace::query()->find($task->workspace_id);
        if (app(ReviewService::class)->satisfied($task) || ($workspace !== null && Gate::forUser($actor)->allows('lead', $workspace))) {
            return;
        }

        $waiting = app(ReviewService::class)->progress($task)['waiting'];
        throw ValidationException::withMessages([
            'status_id' => __('Waiting for review by :names.', ['names' => implode(', ', $waiting)]),
        ]);
    }

    /**
     * R-23: carry a running number or date forward so each repeat is distinguishable:
     * "Report – week 1" -> "Report – week 2", "Post #7" -> "Post #8",
     * "Audit 2026-10-16" -> "Audit 2026-10-23", "SEO report Oct 16" -> "SEO report Oct 23".
     * Titles without either stay as they are (the due date tells them apart).
     */
    public function nextTitle(string $title, CarbonInterface $oldDue, CarbonInterface $newDue): string
    {
        foreach ([[$oldDue->toDateString(), $newDue->toDateString()], [$oldDue->format('M j'), $newDue->format('M j')], [$oldDue->format('F j'), $newDue->format('F j')]] as [$from, $to]) {
            $pattern = '/(?<![\\d])'.preg_quote($from, '/').'(?!\\d)/u';
            if (preg_match($pattern, $title) === 1) {
                return (string) preg_replace($pattern, $to, $title, 1);
            }
        }

        $bumped = preg_replace_callback(
            '/\b(week|wk|sprint|issue|part|round|batch|no\.?)(\s*)(\d{1,4})(?!.*\b(?:week|wk|sprint|issue|part|round|batch|no\.?)\s*\d)/iu',
            fn (array $m) => $m[1].$m[2].((int) $m[3] + 1),
            $title,
            1,
        );
        if (is_string($bumped) && $bumped !== $title) {
            return $bumped;
        }

        $hashed = preg_replace_callback('/#(\d{1,5})(?!.*#\d)/u', fn (array $m) => '#'.((int) $m[1] + 1), $title, 1);

        return is_string($hashed) ? $hashed : $title;
    }

    /**
     * B-11: copy a task (fields, labels, open checklist of subtasks) as a fresh To Do.
     */
    public function duplicate(Task $task, User $actor): Task
    {
        $workspace = Workspace::query()->findOrFail($task->workspace_id);
        $copy = $this->create($workspace, [
            'title' => __('Copy of :title', ['title' => $task->title]),
            'description' => $task->description,
            'project_id' => $task->project_id,
            'department_id' => $task->department_id,
            'assignee_id' => $task->assignee_id,
            'priority' => $task->priority,
            'type' => $task->type,
            'estimate_minutes' => $task->estimate_minutes,
            'work_details' => $task->work_details,
            'due_on' => $task->due_on?->toDateString(),
            'start_on' => $task->start_on?->toDateString(),
            'label_ids' => $task->labels()->pluck('labels.id')->all(),
        ], $actor);

        foreach ($task->subtasks()->get() as $sub) {
            $this->create($workspace, ['title' => $sub->title, 'department_id' => $sub->department_id, 'parent_id' => $copy->id], $actor);
        }

        $this->activity->log('task.duplicated', $copy, ['from_task_id' => $task->id], $actor);

        return $copy;
    }

    private function stampCompletion(Task $task): void
    {
        $status = TaskStatus::ordered()->firstWhere('id', $task->status_id);
        $task->forceFill(['completed_at' => $status?->isDone() ? ($task->completed_at ?? now()) : null]);
    }

    private function nextPosition(int $workspaceId, int $statusId): int
    {
        return (int) Task::query()->where('workspace_id', $workspaceId)->where('status_id', $statusId)->max('position') + 1;
    }

    /**
     * Human-readable from/to pairs for the timeline.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function describeChanges(array $before, array $after): array
    {
        $changes = [];
        foreach (self::TRACKED as $field) {
            $from = $before[$field] ?? null;
            $to = $after[$field] ?? null;
            if ((string) $from === (string) $to) {
                continue;
            }

            $changes[$field] = ['from' => $this->label($field, $from), 'to' => $this->label($field, $to)];
        }

        return $changes;
    }

    private function label(string $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'status_id' => TaskStatus::ordered()->firstWhere('id', (int) $value)?->name,
            'assignee_id' => User::whereKey((int) $value)->value('name'),
            'department_id' => Department::whereKey((int) $value)->value('name'),
            'project_id' => Project::whereKey((int) $value)->value('name'),
            'sprint_id' => Sprint::whereKey((int) $value)->value('name'),
            'due_on', 'start_on', 'recurrence_until' => substr((string) $value, 0, 10),
            'recurrence' => Task::RECURRENCES[(string) $value] ?? $value,
            default => $value,
        };
    }
}
