<?php

namespace App\Http\Controllers\Work;

use App\Domain\Work\TaskPresenter;
use App\Domain\Workspaces\WorkspacePresenter;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Month or week calendar of tasks inside a workspace (S1-17). A task with a start
 * date shows as a bar from start to due; filters match the task list.
 */
class CalendarController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, TaskPresenter $present, WorkspacePresenter $workspaces): Response
    {
        Gate::authorize('view', $workspace);

        $q = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'view' => ['nullable', Rule::in(['month', 'week'])],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'department' => ['nullable', 'integer'],
            'project' => ['nullable', 'integer'],
            'assignee' => ['nullable', 'string', 'max:20'],
            'include_done' => ['nullable', 'boolean'],
        ]);
        $view = $q['view'] ?? 'month';

        if ($view === 'week') {
            $anchor = CarbonImmutable::parse($q['date'] ?? now()->toDateString())->startOfDay();
            $gridStart = $anchor->startOfWeek(CarbonImmutable::MONDAY);
            $gridEnd = $gridStart->addDays(6);
            $month = $gridStart->format('Y-m');
        } else {
            $first = CarbonImmutable::parse(($q['month'] ?? now()->format('Y-m')).'-01')->startOfDay();
            // Grid runs Monday..Sunday covering the whole month.
            $gridStart = $first->startOfWeek(CarbonImmutable::MONDAY);
            $gridEnd = $first->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);
            $month = $first->format('Y-m');
        }
        $from = $gridStart->toDateString();
        $to = $gridEnd->toDateString();

        $query = $workspace->tasks()->getQuery()
            ->with(TaskPresenter::WITH)
            ->whereNotNull('due_on')
            // Due inside the range, or a start-to-due bar that crosses it.
            // whereDate: SQLite stores dates with a time part, so plain string compares miss the last day.
            ->where(fn (Builder $b) => $b
                ->where(fn (Builder $d) => $d->whereDate('due_on', '>=', $from)->whereDate('due_on', '<=', $to))
                ->orWhere(fn (Builder $o) => $o->whereNotNull('start_on')->whereDate('start_on', '<=', $to)->whereDate('due_on', '>=', $from)));
        $present->applyFilters($query, [
            'department' => $q['department'] ?? null,
            'project' => $q['project'] ?? null,
            'assignee' => $q['assignee'] ?? null,
            'include_done' => $q['include_done'] ?? true,
        ], $request->user()->id);

        $tasks = $query->orderBy('due_on')->limit(500)->get();
        $open = $present->openDependencyMap($tasks->pluck('id')->all());

        return Inertia::render('tasks/Calendar', [
            'workspace' => $workspaces->header($workspace, $request->user()),
            'view' => $view,
            'month' => $month,
            'gridStart' => $from,
            'gridEnd' => $to,
            'filters' => array_filter([
                'department' => $q['department'] ?? null,
                'project' => $q['project'] ?? null,
                'assignee' => $q['assignee'] ?? null,
            ], fn ($v) => $v !== null),
            'tasks' => $tasks->map(fn (Task $t): array => $present->row($t, $open))->values(),
            'members' => $workspaces->memberOptions($workspace),
            'departments' => $workspaces->departmentOptions(),
            'projects' => $present->formOptions($workspace)['projects'],
        ]);
    }
}
