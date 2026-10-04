<?php

namespace App\Http\Controllers\Work;

use App\Domain\Work\TaskPresenter;
use App\Domain\Workspaces\WorkspacePresenter;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Month view of tasks by due date inside a workspace.
 */
class CalendarController extends Controller
{
    public function __invoke(Request $request, Workspace $workspace, TaskPresenter $present, WorkspacePresenter $workspaces): Response
    {
        Gate::authorize('view', $workspace);

        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? now()->format('Y-m');
        $first = CarbonImmutable::parse($month.'-01')->startOfDay();
        // Grid runs Monday..Sunday covering the whole month.
        $gridStart = $first->startOfWeek(CarbonImmutable::MONDAY);
        $gridEnd = $first->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

        $tasks = $workspace->tasks()->getQuery()
            ->with(TaskPresenter::WITH)
            ->whereNotNull('due_on')
            ->whereDate('due_on', '>=', $gridStart->toDateString())
            ->whereDate('due_on', '<=', $gridEnd->toDateString())
            ->orderBy('due_on')
            ->limit(500)
            ->get();

        $open = $present->openDependencyMap($tasks->pluck('id')->all());

        return Inertia::render('tasks/Calendar', [
            'workspace' => $workspaces->header($workspace, $request->user()),
            'month' => $first->format('Y-m'),
            'gridStart' => $gridStart->toDateString(),
            'gridEnd' => $gridEnd->toDateString(),
            'tasks' => $tasks->map(fn (Task $t): array => $present->row($t, $open))->values(),
        ]);
    }
}
