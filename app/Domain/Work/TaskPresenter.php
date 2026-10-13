<?php

namespace App\Domain\Work;

use App\Models\Label;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Shapes tasks for lists, boards and queues. Expects the relations from
 * self::WITH to be eager-loaded.
 */
class TaskPresenter
{
    /** Relations every task row needs. */
    public const WITH = [
        'status:id,name,slug,category,color',
        'assignee:id,name',
        'department:id,name,slug,color',
        'project:id,name',
        'labels:id,name,color',
        'workspace:id,name,slug,color,key,status_labels',
        'sprint:id,name,status',
    ];

    /** @var array<int, int> task_id => number of linked items (filled by openDependencyMap) */
    private array $linkCounts = [];

    /**
     * @param  array<int, int>  $openDependencies  task_id => open dependency count
     * @return array<string, mixed>
     */
    public function row(Task $task, array $openDependencies = []): array
    {
        return [
            'id' => $task->id,
            'key' => $task->keyLabel(),
            'type' => $task->type,
            'title' => $task->title,
            'priority' => $task->priority,
            // B-07: this client's name for the status.
            'status' => ['name' => $task->workspace->statusLabel($task->status_id, $task->status->name)] + $task->status->only(['id', 'slug', 'category', 'color']),
            'assignee' => $task->assignee?->only(['id', 'name']),
            'department' => $task->department?->only(['id', 'name', 'slug', 'color']),
            'project' => $task->project?->only(['id', 'name']),
            'labels' => $task->labels->map(fn (Label $l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values(),
            'workspace' => $task->workspace->only(['id', 'name', 'slug', 'color']),
            'due_on' => $task->due_on?->toDateString(),
            'start_on' => $task->start_on?->toDateString(),
            'estimate_minutes' => $task->estimate_minutes,
            'position' => $task->position,
            'waiting_on' => $openDependencies[$task->id] ?? 0,
            'is_handoff' => $task->handoff_from_task_id !== null,
            'linked' => $this->linkCounts[$task->id] ?? 0,
            'story_points' => $task->story_points,
            'sprint' => $task->sprint_id !== null && $task->relationLoaded('sprint') ? $task->sprint?->only(['id', 'name', 'status']) : null,
            'sprint_id' => $task->sprint_id,
            'recurrence' => $task->recurrence,
        ];
    }

    /**
     * Open dependency counts for many tasks in one query (avoids N+1).
     *
     * @param  list<int>  $taskIds
     * @return array<int, int>
     */
    public function openDependencyMap(array $taskIds): array
    {
        if ($taskIds === []) {
            return [];
        }

        // Rows also show how many things each task is linked to; same task ids, so load them here.
        $this->linkCounts = $this->linkCountMap($taskIds) + $this->linkCounts;

        return DB::table('task_dependencies as d')
            ->join('tasks as t', 't.id', '=', 'd.depends_on_task_id')
            ->whereIn('d.task_id', $taskIds)
            ->whereNull('t.deleted_at')
            ->whereNotIn('t.status_id', $this->doneStatusIds())
            ->groupBy('d.task_id')
            ->selectRaw('d.task_id, count(*) as open_count')
            ->pluck('open_count', 'task_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * @return list<int>
     */
    public function doneStatusIds(): array
    {
        return TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * @return list<array{id: int, name: string, slug: string, category: string, color: string}>
     */
    public function statuses(?Workspace $workspace = null): array
    {
        return TaskStatus::ordered()
            ->map(fn (TaskStatus $s) => ['id' => $s->id, 'name' => $workspace?->statusLabel($s->id, $s->name) ?? $s->name, 'slug' => $s->slug, 'category' => $s->category, 'color' => $s->color])
            ->values()
            ->all();
    }

    /**
     * Options for task forms inside a workspace.
     *
     * @return array<string, mixed>
     */
    public function formOptions(Workspace $workspace): array
    {
        return [
            'statuses' => $this->statuses($workspace),
            'priorities' => Task::PRIORITIES,
            'projects' => $workspace->projects()->where('status', '!=', 'archived')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'labels' => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
            // Sprints a task can be planned into (not closed).
            'sprintOptions' => $workspace->sprints()->where('status', '!=', 'closed')->orderBy('starts_on')->get(['id', 'name', 'status'])
                ->map(fn (Sprint $s) => ['id' => $s->id, 'name' => $s->name, 'status' => $s->status])->values(),
        ];
    }

    /**
     * Apply the common list filters from the query string.
     *
     * @param  Builder<Task>  $query
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $query, array $filters, int $userId): void
    {
        $query
            ->when($filters['search'] ?? null, fn (Builder $q, $t) => $q->whereRaw('lower(title) like ?', ['%'.mb_strtolower((string) $t).'%']))
            ->when($filters['status'] ?? null, fn (Builder $q, $s) => $q->where('status_id', (int) $s))
            ->when($filters['department'] ?? null, fn (Builder $q, $d) => $q->where('department_id', (int) $d))
            ->when($filters['project'] ?? null, fn (Builder $q, $p) => $q->where('project_id', (int) $p))
            ->when($filters['label'] ?? null, fn (Builder $q, $l) => $q->whereHas('labels', fn (Builder $w) => $w->whereKey((int) $l)))
            ->when(($filters['assignee'] ?? null) === 'me', fn (Builder $q) => $q->where('assignee_id', $userId))
            ->when(($filters['assignee'] ?? null) === 'none', fn (Builder $q) => $q->whereNull('assignee_id'))
            ->when(is_numeric($filters['assignee'] ?? null), fn (Builder $q) => $q->where('assignee_id', (int) $filters['assignee']))
            ->when($filters['type'] ?? null, fn (Builder $q, $t) => $q->where('type', (string) $t))
            ->when(($filters['sprint'] ?? null) === 'backlog', fn (Builder $q) => $q->whereNull('sprint_id'))
            ->when(($filters['sprint'] ?? null) === 'active', fn (Builder $q) => $q->whereHas('sprint', fn (Builder $s) => $s->where('status', 'active')))
            ->when(is_numeric($filters['sprint'] ?? null), fn (Builder $q) => $q->where('sprint_id', (int) $filters['sprint']))
            ->when(($filters['due'] ?? null) === 'overdue', fn (Builder $q) => $q->whereDate('due_on', '<', now()->toDateString()))
            ->when(($filters['due'] ?? null) === 'week', fn (Builder $q) => $q->whereDate('due_on', '>=', now()->toDateString())->whereDate('due_on', '<=', now()->addDays(7)->toDateString()))
            ->when(! ($filters['include_done'] ?? false) && ! ($filters['status'] ?? null), fn (Builder $q) => $q->whereNotIn('status_id', $this->doneStatusIds()));
    }

    /**
     * How many things each task is linked to: references (relates / duplicates /
     * follow-up, both directions), waiting on / blocks, parent and subtasks, handoffs.
     *
     * @param  list<int>  $taskIds
     * @return array<int, int>
     */
    public function linkCountMap(array $taskIds): array
    {
        $counts = array_fill_keys($taskIds, 0);
        $add = function (iterable $rows) use (&$counts): void {
            foreach ($rows as $id => $n) {
                if (isset($counts[(int) $id])) {
                    $counts[(int) $id] += (int) $n;
                }
            }
        };

        $add(DB::table('record_links')->where('source_type', 'task')->whereIn('source_id', $taskIds)
            ->groupBy('source_id')->selectRaw('source_id, count(*) as n')->pluck('n', 'source_id'));
        $add(DB::table('record_links')->where('target_type', 'task')->whereIn('target_id', $taskIds)
            ->groupBy('target_id')->selectRaw('target_id, count(*) as n')->pluck('n', 'target_id'));
        $add(DB::table('task_dependencies')->whereIn('task_id', $taskIds)
            ->groupBy('task_id')->selectRaw('task_id, count(*) as n')->pluck('n', 'task_id'));
        $add(DB::table('task_dependencies')->whereIn('depends_on_task_id', $taskIds)
            ->groupBy('depends_on_task_id')->selectRaw('depends_on_task_id, count(*) as n')->pluck('n', 'depends_on_task_id'));
        $add(DB::table('tasks')->whereNull('deleted_at')->whereIn('parent_id', $taskIds)
            ->groupBy('parent_id')->selectRaw('parent_id, count(*) as n')->pluck('n', 'parent_id'));
        $add(DB::table('tasks')->whereIn('id', $taskIds)->whereNotNull('parent_id')->pluck('id')->mapWithKeys(fn ($id) => [(int) $id => 1]));

        return $counts;
    }
}
