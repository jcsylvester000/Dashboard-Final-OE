<?php

namespace App\Http\Controllers\Work;

use App\Domain\Identity\Permissions;
use App\Domain\Work\TaskPresenter;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cross-workspace views: "My tasks" for everyone and the department queue
 * for leads. Both only include workspaces the viewer can open.
 */
class QueueController extends Controller
{
    public function __construct(private readonly TaskPresenter $present) {}

    public function mine(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $tasks = Task::query()
            ->with(TaskPresenter::WITH)
            ->where('assignee_id', $user->id)
            ->whereNotIn('status_id', $this->present->doneStatusIds())
            ->whereHas('workspace', fn (Builder $q) => $q->visibleTo($user)->where('status', '!=', 'archived'))
            ->orderByRaw('due_on is null, due_on asc')
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->limit(300)
            ->get();

        $open = $this->present->openDependencyMap($tasks->pluck('id')->all());

        return Inertia::render('tasks/Mine', [
            'tasks' => $tasks->map(fn (Task $t): array => $this->present->row($t, $open))->values(),
            'statuses' => $this->present->statuses(),
        ]);
    }

    public function department(Request $request, Department $department): Response
    {
        /** @var User $user */
        $user = $request->user();

        $allowed = $user->can(Permissions::REPORTS_VIEW_ALL)
            || $department->lead_user_id === $user->id
            || $user->primary_department_id === $department->id
            || $user->departments()->whereKey($department->id)->exists();
        abort_unless($allowed, 403);

        $filters = $request->validate([
            'workspace' => ['nullable', 'integer'],
            'assignee' => ['nullable', 'string', 'max:20'],
            'due' => ['nullable', 'in:overdue,week'],
        ]);

        $query = Task::query()
            ->with(TaskPresenter::WITH)
            ->where('department_id', $department->id)
            ->whereHas('workspace', fn (Builder $q) => $q->visibleTo($user)->where('status', '!=', 'archived'))
            ->when($filters['workspace'] ?? null, fn (Builder $q, $id) => $q->where('workspace_id', (int) $id));
        $this->present->applyFilters($query, $filters, $user->id);

        $tasks = $query->orderByRaw('due_on is null, due_on asc')->limit(400)->get();
        $open = $this->present->openDependencyMap($tasks->pluck('id')->all());

        return Inertia::render('departments/Queue', [
            'department' => $department->only(['id', 'name', 'slug', 'color']),
            'tasks' => $tasks->map(fn (Task $t): array => $this->present->row($t, $open))->values(),
            'statuses' => $this->present->statuses(),
            'filters' => $filters,
            'workspaces' => Workspace::query()->visibleTo($user)->where('status', '!=', 'archived')
                ->orderBy('name')->get(['id', 'name']),
            'departments' => Department::query()->orderBy('position')->get(['id', 'name', 'slug', 'color']),
        ]);
    }
}
