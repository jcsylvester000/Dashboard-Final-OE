<?php

namespace App\Http\Controllers\Work;

use App\Domain\Files\AttachmentPresenter;
use App\Domain\Files\AttachmentService;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Text\RichText;
use App\Domain\Work\TaskPresenter;
use App\Domain\Work\TaskService;
use App\Domain\Workspaces\LinkService;
use App\Domain\Workspaces\WorkspacePresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Work\SaveTaskRequest;
use App\Models\ActivityLog;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\RecordLink;
use App\Models\SavedView;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\TaskReviewer;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    /** Group-by options for the list: key => column used to keep groups together. */
    public const GROUPS = [
        'status' => 'status_id',
        'assignee' => 'assignee_id',
        'project' => 'project_id',
        'department' => 'department_id',
        'sprint' => 'sprint_id',
        'priority' => 'priority',
    ];

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

        // Grouped lists sort by the group first so each group is contiguous across pages.
        if (isset($filters['group'])) {
            $column = self::GROUPS[(string) $filters['group']] ?? 'status_id';
            $column === 'priority'
                ? $query->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
                : $query->orderByRaw("{$column} is null")->orderBy($column);
        }

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

        // B-21: cap each column (most boards fit; "Show more" raises the cap) instead of
        // shipping up to 400 cards at once. Totals let each column say how many are hidden.
        $perColumn = max(25, min(500, (int) $request->integer('per_column', 50)));
        $ranked = (clone $query)->toBase()->select('tasks.id')
            ->selectRaw('row_number() over (partition by tasks.status_id order by tasks.position, tasks.id) as board_rank');
        $tasks = Task::query()->with(TaskPresenter::WITH)
            ->whereIn('id', DB::query()->fromSub($ranked, 'ranked')->where('board_rank', '<=', $perColumn)->select('id'))
            ->orderBy('position')
            ->get();
        $totals = (clone $query)->toBase()->select('tasks.status_id')->selectRaw('count(*) as aggregate')->groupBy('tasks.status_id')
            ->pluck('aggregate', 'status_id')->map(fn ($n) => (int) $n);
        $open = $this->present->openDependencyMap($tasks->pluck('id')->all());

        return Inertia::render('tasks/Board', [
            ...$this->shared($workspace, $request->user()),
            'tasks' => $tasks->map(fn (Task $t): array => $this->present->row($t, $open))->values(),
            'columnTotals' => $totals,
            'perColumn' => $perColumn,
            'filters' => $filters,
        ]);
    }

    public function store(SaveTaskRequest $request, Workspace $workspace): RedirectResponse
    {
        // Optional: link the new task to an existing ticket right away.
        $link = $request->validate([
            'link_task_id' => ['nullable', 'integer', Rule::exists('tasks', 'id')->whereNull('deleted_at')],
            'link_relation' => ['nullable', 'required_with:link_task_id', Rule::in(['relates', 'duplicates', 'follows', 'blocks', 'blocked_by'])],
        ]);
        $target = isset($link['link_task_id']) ? Task::query()->findOrFail((int) $link['link_task_id']) : null;
        if ($target !== null) {
            Gate::authorize('view', $target);
            if (in_array($link['link_relation'], ['blocks', 'blocked_by'], true) && $target->workspace_id !== $workspace->id) {
                throw ValidationException::withMessages(['link_relation' => __('Blocking links only work inside the same client. Use "relates to" for other clients.')]);
            }
        }

        /** @var User $user */
        $user = $request->user();
        $task = $this->tasks->create($workspace, $request->validated(), $user);

        if ($target !== null) {
            $relation = (string) $link['link_relation'];
            if ($relation === 'blocks') {
                $target->dependencies()->syncWithoutDetaching([$task->id => ['type' => 'blocks', 'created_by' => $user->id]]);
            } elseif ($relation === 'blocked_by') {
                $task->dependencies()->syncWithoutDetaching([$target->id => ['type' => 'blocks', 'created_by' => $user->id]]);
            } else {
                RecordLink::firstOrCreate(
                    ['source_type' => 'task', 'source_id' => $task->id, 'target_type' => 'task', 'target_id' => $target->id],
                    ['relation' => $relation, 'created_by' => $user->id],
                );
            }
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Task created: :title', ['title' => $task->title]),
            'action' => ['label' => __('Open task'), 'url' => route('workspaces.tasks.show', [$workspace, $task], false)],
        ]);

        return $request->boolean('stay')
            ? back()
            : to_route('workspaces.tasks.show', [$workspace, $task]);
    }

    /**
     * JSON for the global "New task" dialog: clients I can add work to, and for the
     * chosen one its projects and people.
     */
    public function newOptions(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspaces = Workspace::query()->visibleTo($user)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'slug', 'status'])
            ->filter(fn (Workspace $w) => Gate::forUser($user)->allows('contribute', $w))->values();

        $chosenId = (int) $request->query('workspace', (string) ($user->last_workspace_id ?? 0));
        $chosen = $workspaces->firstWhere('id', $chosenId) ?? $workspaces->first();

        return response()->json([
            'workspaces' => $workspaces->map(fn (Workspace $w) => ['id' => $w->id, 'name' => $w->name, 'slug' => $w->slug])->values(),
            'workspace_id' => $chosen?->id,
            'projects' => $chosen !== null ? $this->present->formOptions($chosen)['projects'] : [],
            'members' => $chosen !== null ? $this->workspaces->memberOptions($chosen) : [],
            'departments' => $this->workspaces->departmentOptions(),
            'me' => ['id' => $user->id, 'department_id' => $user->primary_department_id],
        ]);
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

        // E-120: heavy side panels (timeline, time, files) load right after the page
        // as one deferred request, so the task itself shows sooner.
        $timeline = fn () => ActivityLog::query()
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

        $references = $links->linksFor($task, $user);

        // E-20 / E-10: Markdown with mentions and pasted screenshots ([[file:N]] -> this task's files only).
        $rich = app(RichText::class);
        $fileIds = array_values(array_unique([...RichText::fileIds($task->description), ...$task->comments->flatMap(fn (Comment $c) => RichText::fileIds($c->body))->all()]));
        $files = $fileIds === [] ? [] : Attachment::query()
            ->whereIn('id', $fileIds)
            ->where('attachable_type', $task->getMorphClass())->where('attachable_id', $task->id)
            ->get()
            ->mapWithKeys(fn (Attachment $a) => [$a->id => [
                'name' => $a->original_name,
                'url' => app(AttachmentService::class)->url($a),
                'thumb_url' => $a->thumbnail_key !== null ? app(AttachmentService::class)->url($a, thumbnail: true) : null,
                'image' => $a->isImage(),
            ]])->all();

        return Inertia::render('tasks/Show', [
            ...$this->shared($workspace, $user),
            'task' => [
                ...$this->present->row($task, $open),
                'description' => $task->description,
                'description_html' => $rich->render($task->description, $files),
                'bug_report' => $task->bug_report ?? (object) [],
                // E-07 / E-36
                'review_rule' => $task->review_rule,
                'reviewers' => $task->reviewers()->with('user:id,name')->get()->map(fn (TaskReviewer $r) => [
                    'user_id' => $r->user_id, 'name' => $r->user->name ?? 'Former member', 'decision' => $r->decision, 'note' => $r->note,
                    'decided_at' => $r->decided_at?->toIso8601String(),
                ])->values(),
                'checklist_items' => $task->checklistItems()->with('doneBy:id,name')->get()->map(fn (TaskChecklistItem $i) => [
                    'id' => $i->id, 'label' => $i->label, 'required' => $i->required, 'done' => $i->done_at !== null, 'done_by' => $i->doneBy?->name,
                ])->values(),
                'work_details' => $task->work_details ?? (object) [],
                'custom_values' => $task->custom_values ?? (object) [],
                'estimate_minutes' => $task->estimate_minutes,
                'reporter' => $task->reporter?->only(['id', 'name']),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'created_at' => $task->created_at?->toIso8601String(),
                'updated_at' => $task->updated_at?->toIso8601String(),
                'recurrence' => $task->recurrence,
                'recurrence_until' => $task->recurrence_until?->toDateString(),
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
                    'body_html' => $rich->render($c->body, $files),
                    'author' => $c->author?->only(['id', 'name']),
                    'created_at' => $c->created_at->toIso8601String(),
                    'edited_at' => $c->edited_at?->toIso8601String(),
                    'can_edit' => $c->editableBy($user),
                    'can_delete' => $c->user_id === $user->id || Gate::allows('lead', $workspace),
                ])->values(),
            ],
            'linkedWork' => $this->linkedWork($task, $workspace, $references),
            'timeline' => Inertia::defer($timeline, 'panels'),
            // Guests (read-only) don't see who logged what.
            'time' => Inertia::defer(fn () => Gate::allows('update', $task) ? $this->timeSummary($task, $user) : null, 'panels'),
            'workDetailFields' => Task::WORK_DETAIL_FIELDS,
            'customFields' => $workspace->custom_fields ?? [],
            'links' => $references,
            'canDelete' => Gate::allows('delete', $task),
            'attachments' => Inertia::defer(fn () => app(AttachmentPresenter::class)->for($task, $workspace, $user), 'panels'),
            // Files from the step that handed this task off (approved first), read-only here.
            'handoffFiles' => Inertia::defer(fn () => $task->handoffFrom !== null
                ? app(AttachmentPresenter::class)->for($task->handoffFrom, $workspace, $user, readOnly: true)
                : [], 'panels'),
            'canReview' => Gate::allows('review', $task),
            // S2-13: the project needs a lead's sign-off before Done.
            'reviewRequired' => $task->project_id !== null && (bool) Project::query()->whereKey($task->project_id)->value('review_required'),
            'fileLimits' => app(AttachmentPresenter::class)->limits(),
        ]);
    }

    public function update(SaveTaskRequest $request, Workspace $workspace, Task $task): RedirectResponse
    {
        $data = $request->validated();

        if (isset($data['parent_id']) && (int) $data['parent_id'] === $task->id) {
            throw ValidationException::withMessages(['parent_id' => __('A task cannot be its own parent.')]);
        }

        /** @var User $user */
        $user = $request->user();
        $this->guardConflict($task, $data['_seen_at'] ?? null, $user);
        unset($data['_seen_at']);

        $this->tasks->update($task, $data, $user);
        // S3-18: say what happened to the time already logged on the task.
        if (($move = $this->tasks->lastTimeMove) !== null && ($move['moved'] > 0 || $move['kept'] > 0)) {
            $msg = $move['moved'] > 0 ? __(':h of unbilled time moved with the task.', ['h' => $this->hours($move['moved'])]) : '';
            if ($move['kept'] > 0) {
                $msg = trim($msg.' '.__(':h already billed or in a locked period stays on the old project.', ['h' => $this->hours($move['kept'])]));
            }
            Inertia::flash('toast', ['type' => 'success', 'message' => $msg]);
        }

        return back();
    }

    /**
     * B-11: copy the task (fields, labels, subtasks) and open the copy.
     */
    public function duplicate(Request $request, Workspace $workspace, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);
        /** @var User $user */
        $user = $request->user();
        $copy = $this->tasks->duplicate($task, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Task duplicated. You are now on the copy.')]);

        return to_route('workspaces.tasks.show', [$workspace, $copy]);
    }

    private function hours(int $minutes): string
    {
        return sprintf('%dh%s', intdiv($minutes, 60), $minutes % 60 ? sprintf(' %02dm', $minutes % 60) : '');
    }

    /**
     * B-12: someone else saved this task after the editor opened it. Refuse instead of
     * silently overwriting; the editor reloads and sees the other change.
     */
    private function guardConflict(Task $task, mixed $seenAt, User $user): void
    {
        if (! is_string($seenAt) || $seenAt === '' || $task->updated_at === null) {
            return;
        }
        if ($task->updated_at->lte(CarbonImmutable::parse($seenAt)->addSecond())) {
            return;
        }

        $last = ActivityLog::query()->with('actor:id,name')
            ->where('subject_type', $task->getMorphClass())->where('subject_id', $task->id)
            ->latest('id')->first();
        // My own quick successive saves are not a conflict.
        if ($last === null || $last->actor_id === $user->id) {
            return;
        }

        throw ValidationException::withMessages(['conflict' => __(':name changed this task :ago. Reload to see their change, then try again.', [
            'name' => $last->actor->name ?? __('Someone'),
            'ago' => $task->updated_at->diffForHumans(),
        ])]);
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
     * Everything this task is connected to, in one list (Jira-style "linked work").
     *
     * @param  array<int, array<string, mixed>>  $references
     * @return list<array{relation: string, id: int|null, title: string, status: array<string, mixed>|null, url: string}>
     */
    private function linkedWork(Task $task, Workspace $workspace, array $references): array
    {
        $row = fn (string $relation, Task $t) => [
            'relation' => $relation,
            'id' => $t->id,
            'title' => $t->title,
            'status' => $t->status->only(['name', 'color', 'category']),
            'url' => route('workspaces.tasks.show', [$workspace, $t], false),
        ];
        $out = [];

        if ($task->parent_id !== null && ($parent = Task::query()->with('status:id,name,color,category')->find($task->parent_id)) !== null) {
            $out[] = $row('Subtask of', $parent);
        }
        if ($task->handoff_from_task_id !== null && ($from = Task::query()->with('status:id,name,color,category')->find($task->handoff_from_task_id)) !== null) {
            $out[] = $row('Handed off from', $from);
        }
        foreach ($task->dependencies as $d) {
            $out[] = $row('Waiting on', $d);
        }
        foreach ($task->dependents as $d) {
            $out[] = $row('Blocks', $d);
        }
        foreach (Task::query()->with('status:id,name,color,category')->where('handoff_from_task_id', $task->id)->get() as $h) {
            $out[] = $row('Handed off to', $h);
        }
        foreach ($task->subtasks as $sub) {
            $out[] = $row('Parent of', $sub);
        }
        // B-11: linked tasks show their current status too.
        $refTasks = Task::query()->with('status:id,name,color,category')
            ->whereIn('id', collect($references)->where('type', 'task')->pluck('record_id')->all())
            ->get()->keyBy('id');
        foreach ($references as $ref) {
            $other = ($ref['type'] ?? null) === 'task' ? $refTasks->get((int) $ref['record_id']) : null;
            $out[] = [
                'relation' => (string) ($ref['relation_label'] ?? 'Relates to'),
                'id' => $other?->id,
                'title' => (string) $ref['label'],
                'status' => $other?->status->only(['name', 'color', 'category']),
                'url' => (string) $ref['url'],
            ];
        }

        return $out;
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
            'type' => ['nullable', Rule::in(array_keys(Task::TYPES))],
            'sprint' => ['nullable', 'string', 'max:20'],
            'group' => ['nullable', Rule::in(array_keys(self::GROUPS))],
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
            'views' => $workspace->views()->visibleTo($user)->with('user:id,name')->orderBy('name')->get()
                ->map(fn (SavedView $v) => [
                    'id' => $v->id,
                    'name' => $v->name,
                    'page' => $v->page,
                    'filters' => (object) $v->filters,
                    'shared' => $v->shared,
                    'owner' => $v->user->name,
                    'can_delete' => $v->user_id === $user->id || ($v->shared && Gate::forUser($user)->allows('lead', $workspace)),
                ])->values(),
        ];
    }
}
