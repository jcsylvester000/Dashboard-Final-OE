<?php

namespace App\Http\Controllers\Work;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Notifications\AlertBatch;
use App\Domain\Notifications\Notifier;
use App\Domain\Work\TaskService;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Bulk changes on the task list (brutal scenarios M2 / J3): select many tasks,
 * then change status, owner, priority, type, project, due date (set or shift) or delete.
 * Each task goes through TaskService::update, so history, alerts and the
 * "can't finish while waiting" rule apply exactly as for a single edit.
 */
class TaskBulkController extends Controller
{
    public const MAX = 200;

    public function __invoke(Request $request, Workspace $workspace, TaskService $tasks, ActivityLogger $activity, AlertBatch $batch, Notifier $notifier): RedirectResponse
    {
        Gate::authorize('contribute', $workspace);

        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['update', 'delete'])],
            'status_id' => ['nullable', 'integer', Rule::exists('task_statuses', 'id')],
            // "none" clears the owner.
            'assignee_id' => ['nullable', function (string $attr, mixed $value, Closure $fail) use ($workspace) {
                if ($value === 'none') {
                    return;
                }
                if (! is_numeric($value) || ! $workspace->members()->whereKey((int) $value)->exists()) {
                    $fail(__('The new owner must be a member of this client.'));
                }
            }],
            'priority' => ['nullable', Rule::in(array_keys(Task::PRIORITIES))],
            'type' => ['nullable', Rule::in(array_keys(Task::TYPES))],
            'project_id' => ['nullable', function (string $attr, mixed $value, Closure $fail) use ($workspace) {
                if ($value !== 'none' && (! is_numeric($value) || ! $workspace->projects()->whereKey((int) $value)->exists())) {
                    $fail(__('Choose a project from this client.'));
                }
            }],
            'sprint_id' => ['nullable', function (string $attr, mixed $value, Closure $fail) use ($workspace) {
                if ($value !== 'none' && (! is_numeric($value) || ! $workspace->sprints()->whereKey((int) $value)->where('status', '!=', 'closed')->exists())) {
                    $fail(__('Choose a planned or active sprint of this client.'));
                }
            }],
            'story_points' => ['nullable', 'integer', 'min:0', 'max:100'],
            'due_on' => ['nullable', 'date'],
            'due_shift_days' => ['nullable', 'integer', 'between:-365,365'],
        ]);

        $selected = $workspace->tasks()->whereIn('id', $data['ids'])->get()
            ->each(fn (Task $t) => $t->setRelation('workspace', $workspace));

        if ($data['action'] === 'delete') {
            $deleted = 0;
            foreach ($selected as $task) {
                if (Gate::allows('delete', $task)) {
                    $task->delete();
                    $activity->log('task.deleted', $task, ['workspace_id' => $workspace->id, 'bulk' => true], $user);
                    $deleted++;
                }
            }
            $this->toast($deleted, $selected->count() - $deleted, __('deleted'));

            return back();
        }

        $changes = array_filter([
            'status_id' => $data['status_id'] ?? null,
            'assignee_id' => isset($data['assignee_id']) ? ($data['assignee_id'] === 'none' ? 'none' : (int) $data['assignee_id']) : null,
            'priority' => $data['priority'] ?? null,
            'type' => $data['type'] ?? null,
            'project_id' => isset($data['project_id']) ? ($data['project_id'] === 'none' ? 'none' : (int) $data['project_id']) : null,
            'sprint_id' => isset($data['sprint_id']) ? ($data['sprint_id'] === 'none' ? 'none' : (int) $data['sprint_id']) : null,
            'story_points' => isset($data['story_points']) ? (int) $data['story_points'] : null,
            'due_on' => $data['due_on'] ?? null,
        ], fn ($v) => $v !== null);
        $shift = (int) ($data['due_shift_days'] ?? 0);

        if ($changes === [] && $shift === 0) {
            throw ValidationException::withMessages(['action' => __('Choose at least one change.')]);
        }

        $updated = 0;
        $skipped = [];
        // One grouped alert per person instead of one per task (R-10).
        $batch->run(function () use ($selected, $changes, $shift, $tasks, $user, &$updated, &$skipped) {
            foreach ($selected as $task) {
                if (! Gate::allows('update', $task)) {
                    $skipped[] = $task->keyLabel();

                    continue;
                }

                $fill = array_map(fn ($v) => $v === 'none' ? null : $v, $changes);
                if ($shift !== 0 && ! isset($fill['due_on']) && $task->due_on !== null) {
                    $fill['due_on'] = $task->due_on->copy()->addDays($shift)->toDateString();
                }

                try {
                    $tasks->update($task, $fill, $user);
                    $updated++;
                } catch (ValidationException) {
                    // e.g. "can't finish while waiting on open tasks": leave that one as it was.
                    $skipped[] = $task->title;
                }
            }
        }, $notifier);

        $this->toast($updated, count($skipped), __('updated'), $skipped);

        return back();
    }

    /**
     * @param  list<string>  $skipped
     */
    private function toast(int $done, int $skippedCount, string $verb, array $skipped = []): void
    {
        $message = __(':n tasks :verb.', ['n' => $done, 'verb' => $verb]);
        if ($skippedCount > 0) {
            $message .= ' '.__(':n skipped (no permission or still waiting on open work)', ['n' => $skippedCount])
                .($skipped !== [] ? ': '.implode(', ', array_slice($skipped, 0, 3)).($skippedCount > 3 ? '…' : '') : '.');
        }

        Inertia::flash('toast', ['type' => $skippedCount > 0 ? 'warning' : 'success', 'message' => $message]);
    }
}
