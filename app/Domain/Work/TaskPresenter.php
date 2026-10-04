<?php

namespace App\Domain\Work;

use App\Models\Label;
use App\Models\Project;
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
        'workspace:id,name,slug,color',
    ];

    /**
     * @param  array<int, int>  $openDependencies  task_id => open dependency count
     * @return array<string, mixed>
     */
    public function row(Task $task, array $openDependencies = []): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'priority' => $task->priority,
            'status' => $task->status->only(['id', 'name', 'slug', 'category', 'color']),
            'assignee' => $task->assignee?->only(['id', 'name']),
            'department' => $task->department?->only(['id', 'name', 'slug', 'color']),
            'project' => $task->project?->only(['id', 'name']),
            'labels' => $task->labels->map(fn (Label $l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values(),
            'workspace' => $task->workspace->only(['id', 'name', 'slug', 'color']),
            'due_on' => $task->due_on?->toDateString(),
            'position' => $task->position,
            'waiting_on' => $openDependencies[$task->id] ?? 0,
            'is_handoff' => $task->handoff_from_task_id !== null,
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
    public function statuses(): array
    {
        return TaskStatus::ordered()
            ->map(fn (TaskStatus $s) => ['id' => $s->id, 'name' => $s->name, 'slug' => $s->slug, 'category' => $s->category, 'color' => $s->color])
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
            'statuses' => $this->statuses(),
            'priorities' => Task::PRIORITIES,
            'projects' => $workspace->projects()->where('status', '!=', 'archived')->orderBy('name')->get(['id', 'name'])
                ->map(fn (Project $p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'labels' => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
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
            ->when(($filters['due'] ?? null) === 'overdue', fn (Builder $q) => $q->whereDate('due_on', '<', now()->toDateString()))
            ->when(($filters['due'] ?? null) === 'week', fn (Builder $q) => $q->whereDate('due_on', '>=', now()->toDateString())->whereDate('due_on', '<=', now()->addDays(7)->toDateString()))
            ->when(! ($filters['include_done'] ?? false) && ! ($filters['status'] ?? null), fn (Builder $q) => $q->whereNotIn('status_id', $this->doneStatusIds()));
    }
}
