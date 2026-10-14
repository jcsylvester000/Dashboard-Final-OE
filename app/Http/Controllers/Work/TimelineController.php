<?php

namespace App\Http\Controllers\Work;

use App\Domain\Work\TaskPresenter;
use App\Domain\Workspaces\WorkspacePresenter;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Timeline / Gantt for a client (B-02, S2-09): projects and campaigns as bars, their
 * tasks start-to-due underneath, milestones as diamonds, sprints as bands and
 * "waits on" links between tasks. Read-only; dates are edited on the task page.
 */
class TimelineController extends Controller
{
    public const MAX_TASKS = 300;

    public function __invoke(Request $request, Workspace $workspace, TaskPresenter $present, WorkspacePresenter $workspaces): Response
    {
        Gate::authorize('view', $workspace);

        $q = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'weeks' => ['nullable', 'integer', 'in:4,8,13,26'],
            'project' => ['nullable', 'integer'],
            'department' => ['nullable', 'integer'],
            'include_done' => ['nullable', 'boolean'],
        ]);
        $weeks = (int) ($q['weeks'] ?? 8);
        $start = CarbonImmutable::parse($q['from'] ?? now()->subWeeks(2)->toDateString())->startOfWeek(CarbonImmutable::MONDAY);
        $end = $start->addWeeks($weeks)->subDay();
        [$from, $to] = [$start->toDateString(), $end->toDateString()];

        $overlaps = fn (Builder $b, string $startCol, string $endCol) => $b
            ->whereNotNull($endCol)
            ->whereDate($endCol, '>=', $from)
            // No start date: the bar is just the due day, so the due date must be in range too.
            ->where(fn (Builder $s) => $s->where(fn (Builder $n) => $n->whereNull($startCol)->whereDate($endCol, '<=', $to))
                ->orWhereDate($startCol, '<=', $to));

        $projects = $workspace->projects()->getQuery()
            ->where('status', '!=', 'archived')
            ->when($q['project'] ?? null, fn (Builder $b, $id) => $b->whereKey((int) $id))
            ->withCount([
                'tasks as tasks_total' => fn (Builder $t) => $t->whereNull('parent_id'),
                'tasks as tasks_done' => fn (Builder $t) => $t->whereNull('parent_id')->whereNotNull('completed_at'),
            ])
            ->orderByRaw('start_on is null, start_on')
            ->limit(200) // E-122
            ->get();

        $taskQuery = $workspace->tasks()->getQuery()
            ->with(TaskPresenter::WITH)
            ->whereNull('parent_id')
            ->where(fn (Builder $b) => $overlaps($b, 'start_on', 'due_on'))
            ->when($q['project'] ?? null, fn (Builder $b, $id) => $b->where('project_id', (int) $id))
            ->when($q['department'] ?? null, fn (Builder $b, $id) => $b->where('department_id', (int) $id))
            ->when(! ($q['include_done'] ?? true), fn (Builder $b) => $b->whereNull('completed_at'))
            ->orderByRaw('coalesce(start_on, due_on)')
            ->orderBy('id');
        $total = (clone $taskQuery)->count();
        $tasks = $taskQuery->limit(self::MAX_TASKS)->get();
        $ids = $tasks->pluck('id')->all();
        $open = $present->openDependencyMap($ids);

        return Inertia::render('tasks/Timeline', [
            'workspace' => $workspaces->header($workspace, $request->user()),
            'range' => ['from' => $from, 'to' => $to, 'weeks' => $weeks, 'today' => now()->toDateString()],
            'filters' => array_filter(['project' => $q['project'] ?? null, 'department' => $q['department'] ?? null], fn ($v) => $v !== null),
            'projects' => $projects->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type,
                'status' => $p->status,
                'start_on' => $p->start_on?->toDateString(),
                'due_on' => $p->due_on?->toDateString(),
                'tasks_total' => (int) $p->getAttribute('tasks_total'),
                'tasks_done' => (int) $p->getAttribute('tasks_done'),
            ])->values(),
            'tasks' => $tasks->map(fn (Task $t): array => $present->row($t, $open))->values(),
            'truncated' => $total > count($ids) ? $total : null,
            'dependencies' => $ids === [] ? [] : DB::table('task_dependencies')
                ->whereIn('task_id', $ids)->whereIn('depends_on_task_id', $ids)
                ->get(['task_id', 'depends_on_task_id'])
                ->map(fn ($d) => ['task' => (int) $d->task_id, 'waits_on' => (int) $d->depends_on_task_id])->values(),
            'sprints' => Sprint::query()->where('workspace_id', $workspace->id)
                ->whereDate('ends_on', '>=', $from)->whereDate('starts_on', '<=', $to)
                // With a project filter, only that project's sprints (and client-wide ones).
                ->when($q['project'] ?? null, fn (Builder $b, $id) => $b->where(fn (Builder $s) => $s->whereNull('project_id')->orWhere('project_id', (int) $id)))
                ->orderBy('starts_on')
                ->get(['id', 'name', 'status', 'starts_on', 'ends_on'])
                ->map(fn (Sprint $s) => ['id' => $s->id, 'name' => $s->name, 'status' => $s->status, 'starts_on' => $s->starts_on->toDateString(), 'ends_on' => $s->ends_on->toDateString()])->values(),
            'departments' => $workspaces->departmentOptions(),
            'projectOptions' => $present->formOptions($workspace)['projects'],
        ]);
    }
}
