<?php

namespace App\Domain\Work;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Workspaces\MentionService;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates and changes tasks, keeping the timeline (activity log), watchers,
 * mentions and the "can't finish while waiting on others" rule consistent.
 */
class TaskService
{
    /** Fields whose changes appear on the task timeline. */
    private const TRACKED = ['title', 'status_id', 'assignee_id', 'department_id', 'project_id', 'due_on', 'priority'];

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
                ...Arr::only($data, ['project_id', 'department_id', 'parent_id', 'title', 'priority', 'assignee_id', 'due_on', 'estimate_minutes']),
                'workspace_id' => $workspace->id,
                'status_id' => $statusId,
                'description' => $normalized['text'],
                'reporter_id' => $actor->id,
                'priority' => $data['priority'] ?? 'normal',
                'position' => $this->nextPosition($workspace->id, $statusId),
            ]);
            $this->stampCompletion($task);
            $task->save();

            if (array_key_exists('label_ids', $data)) {
                $task->labels()->sync(array_map('intval', (array) $data['label_ids']));
            }
            $this->watch($task, [$actor->id, $task->assignee_id]);
            $this->mentions->sync($task, $normalized['user_ids'], $actor);

            $this->activity->log('task.created', $task, ['workspace_id' => $workspace->id], $actor);

            return $task;
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated input (only keys present are changed)
     */
    public function update(Task $task, array $data, User $actor): Task
    {
        return DB::transaction(function () use ($task, $data, $actor) {
            $before = Arr::only($task->getAttributes(), self::TRACKED);

            $fill = Arr::only($data, ['project_id', 'department_id', 'status_id', 'parent_id', 'title', 'priority', 'assignee_id', 'due_on', 'estimate_minutes', 'position']);

            $mentionIds = null;
            if (array_key_exists('description', $data)) {
                $normalized = $this->mentions->normalize($data['description'], $task->workspace_id);
                $fill['description'] = $normalized['text'];
                $mentionIds = $normalized['user_ids'];
            }

            $task->fill($fill);

            if ($task->isDirty('status_id')) {
                $this->guardFinish($task);
                $this->stampCompletion($task);
            }

            $task->save();

            if (array_key_exists('label_ids', $data)) {
                $task->labels()->sync(array_map('intval', (array) $data['label_ids']));
            }
            if ($task->wasChanged('assignee_id')) {
                $this->watch($task, [$task->assignee_id]);
            }
            if ($mentionIds !== null) {
                $this->mentions->sync($task, $mentionIds, $actor);
            }

            $changes = $this->describeChanges($before, Arr::only($task->getAttributes(), self::TRACKED));
            if ($changes !== []) {
                $this->activity->log('task.updated', $task, ['changes' => $changes], $actor);
            }

            if ($task->wasChanged('status_id') && $task->completed_at !== null) {
                $this->advanceDependents($task, $actor);
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
     * When a task is finished, waiting tasks that are still in Backlog and have
     * nothing else open move to To Do - the next department can start.
     */
    private function advanceDependents(Task $task, User $actor): void
    {
        $backlogId = TaskStatus::idFor('backlog');
        $todoId = TaskStatus::idFor('todo');

        $waiting = $task->dependents()->where('tasks.status_id', $backlogId)->get();
        foreach ($waiting as $next) {
            if ($next->openDependencyCount() === 0) {
                $next->forceFill(['status_id' => $todoId])->save();
                $this->activity->log('task.unblocked', $next, ['after_task_id' => $task->id], $actor);
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
            'due_on' => substr((string) $value, 0, 10),
            default => $value,
        };
    }
}
