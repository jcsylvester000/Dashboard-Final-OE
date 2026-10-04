<?php

namespace App\Http\Controllers\Work;

use App\Domain\Files\AttachmentPresenter;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Work\TaskPresenter;
use App\Domain\Work\TaskService;
use App\Domain\Workspaces\LinkService;
use App\Domain\Workspaces\WorkspacePresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Work\SaveTaskRequest;
use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tasks inside a client workspace: list, board, detail and changes.
 */
class TaskController extends Controller
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly TaskPresenter $present,
        private readonly WorkspacePresenter $workspaces,
    ) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);
        $filters = $this->filters($request);

        $query = $workspace->tasks()->getQuery()->with(TaskPresenter::WITH)->whereNull('parent_id');
        $this->present->applyFilters($query, $filters, $request->user()->id);

        $page = $query
            ->orderByRaw('due_on is null, due_on asc')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        $open = $this->present->openDependencyMap($page->getCollection()->pluck('id')->all());

        return Inertia::render('tasks/Index', [
            ...$this->shared($workspace, $request->user()),
            'tasks' => $page->through(fn (Task $t): array => $this->present->row($t, $open)),
            'filters' => $filters,
        ]);
    }

    public function board(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);
        $filters = $this->filters($request);
        $filters['include_done'] = true;

        $query = $workspace->tasks()->getQuery()->with(TaskPresenter::WITH)->whereNull('parent_id')
            // Done column only shows the last two weeks to keep the board light.
            ->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '>=', now()->subDays(14)));
        $this->present->applyFilters($query, $filters, $request->user()->id);

        $tasks = $query->orderBy('position')->limit(400)->get();
        $open = $this->present->openDependencyMap($tasks->pluck('id')->all());

        return Inertia::render('tasks/Board', [
            ...$this->shared($workspace, $request->user()),
            'tasks' => $tasks->map(fn (Task $t): array => $this->present->row($t, $open))->values(),
            'filters' => $filters,
        ]);
    }

    public function store(SaveTaskRequest $request, Workspace $workspace): RedirectResponse
    {
        $task = $this->tasks->create($workspace, $request->validated(), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Task created.')]);

        return $request->boolean('stay')
            ? back()
            : to_route('workspaces.tasks.show', [$workspace, $task]);
    }

    public function show(Request $request, Workspace $workspace, Task $task, LinkService $links): Response
    {
        Gate::authorize('view', $workspace);

        /** @var User $user */
        $user = $request->user();

        $task->load([
            ...TaskPresenter::WITH,
            'reporter:id,name',
            'handoffFrom:id,title,workspace_id',
            'subtasks' => fn ($q) => $q->with(TaskPresenter::WITH),
            'dependencies' => fn ($q) => $q->with(TaskPresenter::WITH),
            'dependents' => fn ($q) => $q->with(TaskPresenter::WITH),
            'watchers:id,name',
            'comments' => fn ($q) => $q->with(['author:id,name'])->oldest(),
        ]);

        // Keep the full route-bound workspace (policies read its status).
        $task->setRelation('workspace', $workspace);

        $related = $task->subtasks->concat($task->dependencies)->concat($task->dependents);
        $open = $this->present->openDependencyMap([$task->id, ...$related->pluck('id')->all()]);

        $timeline = ActivityLog::query()
            ->with('actor:id,name')
            ->where('subject_type', $task->getMorphClass())
            ->where('subject_id', $task->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor?->name,
                'properties' => $log->properties,
                'at' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('tasks/Show', [
            ...$this->shared($workspace, $user),
            'task' => [
                ...$this->present->row($task, $open),
                'description' => $task->description,
                'work_details' => $task->work_details ?? (object) [],
                'estimate_minutes' => $task->estimate_minutes,
                'reporter' => $task->reporter?->only(['id', 'name']),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'created_at' => $task->created_at?->toIso8601String(),
                'parent_id' => $task->parent_id,
                'handoff_from' => $task->handoffFrom?->only(['id', 'title']),
                'subtasks' => $task->subtasks->map(fn (Task $t) => $this->present->row($t, $open))->values(),
                'dependencies' => $task->dependencies->map(fn (Task $t) => [...$this->present->row($t, $open), 'link_type' => $t->getRelation('pivot')->getAttribute('type')])->values(),
                'dependents' => $task->dependents->map(fn (Task $t) => [...$this->present->row($t, $open), 'link_type' => $t->getRelation('pivot')->getAttribute('type')])->values(),
                'watchers' => $task->watchers->map->only(['id', 'name'])->values(),
                'watching' => $task->watchers->contains('id', $user->id),
                'comments' => $task->comments->map(fn (Comment $c) => [
                    'id' => $c->id,
                    'body' => $c->body,
                    'author' => $c->author?->only(['id', 'name']),
                    'created_at' => $c->created_at->toIso8601String(),
                    'edited_at' => $c->edited_at?->toIso8601String(),
                    'can_edit' => $c->editableBy($user),
                    'can_delete' => $c->user_id === $user->id || Gate::allows('lead', $workspace),
                ])->values(),
            ],
            'timeline' => $timeline,
            // Guests (read-only) don't see who logged what.
            'time' => Gate::allows('update', $task) ? $this->timeSummary($task, $user) : null,
            'workDetailFields' => Task::WORK_DETAIL_FIELDS,
            'links' => $links->linksFor($task, $user),
            'canDelete' => Gate::allows('delete', $task),
            'attachments' => app(AttachmentPresenter::class)->for($task, $workspace, $user),
            'fileLimits' => app(AttachmentPresenter::class)->limits(),
            // Same-workspace tasks for the "waiting on" picker.
            'dependencyOptions' => $workspace->tasks()
                ->whereKeyNot($task->id)
                ->whereNotIn('status_id', $this->present->doneStatusIds())
                ->latest('id')
                ->limit(200)
                ->get(['id', 'title'])
                ->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title])
                ->values(),
        ]);
    }

    public function update(SaveTaskRequest $request, Workspace $workspace, Task $task): RedirectResponse
    {
        $data = $request->validated();

        if (isset($data['parent_id']) && (int) $data['parent_id'] === $task->id) {
            throw ValidationException::withMessages(['parent_id' => __('A task cannot be its own parent.')]);
        }

        $this->tasks->update($task, $data, $request->user());

        return back();
    }

    /**
     * Board drag & drop.
     */
    public function move(Request $request, Workspace $workspace, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);

        $data = $request->validate([
            'status_id' => ['required', 'integer', Rule::exists('task_statuses', 'id')],
            'position' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        $this->tasks->move($task, (int) $data['status_id'], (int) $data['position'], $request->user());

        return back();
    }

    public function destroy(Request $request, Workspace $workspace, Task $task, ActivityLogger $activity): RedirectResponse
    {
        Gate::authorize('delete', $task);

        $task->delete();
        $activity->log('task.deleted', $task, ['workspace_id' => $workspace->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Task deleted.')]);

        return to_route('workspaces.tasks.index', $workspace);
    }

    public function watch(Request $request, Workspace $workspace, Task $task): RedirectResponse
    {
        Gate::authorize('view', $task);

        $task->watchers()->toggle([$request->user()->id]);

        return back();
    }

    /**
     * Time logged on the task: totals and the latest entries.
     *
     * @return array<string, mixed>
     */
    private function timeSummary(Task $task, User $user): array
    {
        $entries = TimeEntry::query()
            ->with('user:id,name')
            ->where('task_id', $task->id)
            ->latest('entry_date')
            ->latest('id')
            ->limit(30)
            ->get();

        $totals = TimeEntry::query()->where('task_id', $task->id)
            ->selectRaw('coalesce(sum(minutes), 0) as total')
            ->selectRaw('coalesce(sum(case when is_billable then minutes else 0 end), 0) as billable')
            ->selectRaw('coalesce(sum(case when user_id = ? then minutes else 0 end), 0) as mine', [$user->id])
            ->first();

        $running = TimeEntry::query()->where('user_id', $user->id)->running()->latest('id')->first();

        return [
            'total' => (int) ($totals?->getAttribute('total') ?? 0),
            'billable' => (int) ($totals?->getAttribute('billable') ?? 0),
            'mine' => (int) ($totals?->getAttribute('mine') ?? 0),
            'estimate' => $task->estimate_minutes,
            'running_here' => $running !== null && $running->task_id === $task->id,
            'entries' => $entries->map(fn (TimeEntry $e): array => [
                'id' => $e->id,
                'user' => $e->user->name,
                'is_mine' => $e->user_id === $user->id,
                'entry_date' => $e->entry_date->toDateString(),
                'minutes' => $e->minutes,
                'note' => $e->note,
                'is_billable' => $e->is_billable,
                'running' => $e->isRunning(),
                'locked' => $e->isLocked(),
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'integer'],
            'department' => ['nullable', 'integer'],
            'project' => ['nullable', 'integer'],
            'label' => ['nullable', 'integer'],
            'assignee' => ['nullable', 'string', 'max:20'],
            'due' => ['nullable', Rule::in(['overdue', 'week'])],
            'include_done' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * Props every task page needs.
     *
     * @return array<string, mixed>
     */
    private function shared(Workspace $workspace, User $user): array
    {
        return [
            'workspace' => $this->workspaces->header($workspace, $user),
            'members' => $this->workspaces->memberOptions($workspace),
            'departments' => $this->workspaces->departmentOptions(),
            'templates' => WorkflowTemplate::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            ...$this->present->formOptions($workspace),
            'doneStatusIds' => $this->present->doneStatusIds(),
        ];
    }
}
