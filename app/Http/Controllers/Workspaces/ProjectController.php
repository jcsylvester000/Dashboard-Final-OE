<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Budgets\BudgetService;
use App\Domain\Files\AttachmentPresenter;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Identity\Permissions;
use App\Domain\Identity\ViewAs;
use App\Domain\Work\TaskPresenter;
use App\Domain\Workspaces\LinkService;
use App\Domain\Workspaces\MentionService;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Domain\Workspaces\WorkspacePresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\SaveProjectRequest;
use App\Models\DocumentTemplate;
use App\Models\Label;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProjectController extends Controller
{
    public function __construct(
        private readonly WorkspacePresenter $presenter,
        private readonly MentionService $mentions,
        private readonly ActivityLogger $activity,
    ) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);

        /** @var User $user */
        $user = $request->user();

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in(array_keys(Project::TYPES))],
            'status' => ['nullable', Rule::in(array_keys(Project::STATUSES))],
            'label' => ['nullable', 'integer'],
            'mine' => ['nullable', 'boolean'],
        ]);

        $projects = $workspace->projects()
            ->with(['lead:id,name', 'labels:id,name,color'])
            ->withCount([
                'members',
                // Top-level tasks only, matching the project page progress (S1-05).
                'tasks as tasks_total' => fn (Builder $q) => $q->whereNull('parent_id'),
                'tasks as tasks_done' => fn (Builder $q) => $q->whereNull('parent_id')->whereNotNull('completed_at'),
            ])
            ->when($filters['search'] ?? null, fn (Builder $q, string $t) => $q->whereRaw('lower(name) like ?', ['%'.mb_strtolower($t).'%']))
            ->when($filters['type'] ?? null, fn (Builder $q, string $t) => $q->where('type', $t))
            ->when($filters['status'] ?? null, fn (Builder $q, string $s) => $q->where('status', $s), fn (Builder $q) => $q->where('status', '!=', 'archived'))
            ->when($filters['label'] ?? null, fn (Builder $q, $id) => $q->whereHas('labels', fn (Builder $l) => $l->whereKey((int) $id)))
            ->when($filters['mine'] ?? false, fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('lead_user_id', $user->id)
                ->orWhereHas('members', fn (Builder $m) => $m->whereKey($user->id))))
            ->orderByRaw('due_on is null, due_on asc')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();
        // E-76: hours burn for leads and Finance (one grouped query for the page).
        $seeBudget = $user->can(Permissions::BILLING_VIEW) || app(WorkspaceAccess::class)->canLead($user, $workspace);
        $used = $seeBudget ? app(BudgetService::class)->minutesFor($projects->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->values()->all()) : [];
        $projects = $projects
            ->through(fn (Project $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type,
                'status' => $p->status,
                'lead' => $p->lead?->name,
                'members' => $p->members_count,
                'tasks_total' => (int) $p->getAttribute('tasks_total'),
                'tasks_done' => (int) $p->getAttribute('tasks_done'),
                'labels' => $p->labels->map(fn (Label $l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values(),
                'start_on' => $p->start_on?->toDateString(),
                'due_on' => $p->due_on?->toDateString(),
                'budget_hours_pct' => $seeBudget ? BudgetService::pct($used[$p->id] ?? 0, $p->budget_minutes) : null,
            ]);

        return Inertia::render('projects/Index', [
            'workspace' => $this->presenter->header($workspace, $user),
            'projects' => $projects,
            'filters' => $filters,
            'types' => Project::TYPES,
            'statuses' => Project::STATUSES,
            'labels' => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
            'departments' => $this->presenter->departmentOptions(),
            'members' => $this->presenter->memberOptions($workspace),
        ]);
    }

    public function store(SaveProjectRequest $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validated();
        $normalized = $this->mentions->normalize($data['description'] ?? null, $workspace->id);

        $project = DB::transaction(function () use ($workspace, $data, $normalized, $request) {
            $project = $workspace->projects()->create([
                ...$this->attributes($data),
                'description' => $normalized['text'],
                'created_by' => $request->user()->id,
            ]);
            $this->syncPeopleAndLabels($project, $data);
            $this->mentions->sync($project, $normalized['user_ids'], $request->user());

            return $project;
        });

        $this->activity->log('project.created', $project, ['workspace_id' => $workspace->id, 'type' => $project->type]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project created.')]);

        return to_route('workspaces.projects.show', [$workspace, $project]);
    }

    public function show(Request $request, Workspace $workspace, Project $project, LinkService $links): Response
    {
        Gate::authorize('view', $workspace);

        /** @var User $user */
        $user = $request->user();
        $project->load(['lead:id,name', 'members:id,name,title', 'labels:id,name,color', 'mentions.user:id,name', 'departments:id,name,color']);
        $project->setRelation('workspace', $workspace);

        if ($user->last_workspace_id !== $workspace->id && ! ViewAs::active()) {
            $user->forceFill(['last_workspace_id' => $workspace->id])->saveQuietly();
        }

        return Inertia::render('projects/Show', [
            'workspace' => $this->presenter->header($workspace, $user),
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
                'type' => $project->type,
                'status' => $project->status,
                'description' => $project->description,
                'lead_user_id' => $project->lead_user_id,
                'review_required' => $project->review_required,
                'review_sla_days' => $project->review_sla_days,
                // B-06: client status page (leads see and manage it).
                'status_link' => Gate::allows('lead', $workspace) && $project->status_token !== null ? [
                    'url' => route('project-status.show', $project->status_token),
                    'expires_at' => $project->status_token_expires_at?->toIso8601String(),
                    'views' => $project->status_views,
                    'detail' => $project->status_detail,
                ] : null,
                'can_share_status' => Gate::allows('lead', $workspace),
                'lead' => $project->lead?->name,
                'start_on' => $project->start_on?->toDateString(),
                'due_on' => $project->due_on?->toDateString(),
                'members' => $project->members->map(fn (User $m) => ['id' => $m->id, 'name' => $m->name, 'title' => $m->title])->values(),
                'department_ids' => $project->departments->pluck('id')->map(fn ($id) => (int) $id)->values(),
                'involved_departments' => $project->departments->map(fn ($d) => $d->only(['id', 'name', 'color']))->values(),
                'labels' => $project->labels->map(fn (Label $l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values(),
                'mentioned' => $project->mentions->map(fn ($m) => $m->user?->name)->filter()->values(),
                'created_at' => $project->created_at?->toIso8601String(),
                'updated_at' => $project->updated_at?->toIso8601String(),
            ],
            // E-76: budget and burn (leads: hours; Finance: hours and amount; members: nothing).
            'budget' => app(BudgetService::class)->present($project, $user, $workspace),
            'links' => $links->linksFor($project, $user),
            'types' => Project::TYPES,
            'statuses' => Project::STATUSES,
            'labels' => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
            'members' => $this->presenter->memberOptions($workspace),
            'canDelete' => Gate::allows('lead', $workspace),
            'attachments' => app(AttachmentPresenter::class)->for($project, $workspace, $user),
            'fileLimits' => app(AttachmentPresenter::class)->limits(),
            // E-52: document templates that can be generated for this project.
            'documentTemplates' => DocumentTemplate::query()->where('archived', false)->orderBy('name')->get(['id', 'name', 'kind']),
            ...$this->work($workspace, $project),
        ]);
    }

    /**
     * The project's tasks, progress and task files (UX simulation 2026-10-06).
     *
     * @return array<string, mixed>
     */
    private function work(Workspace $workspace, Project $project): array
    {
        $present = app(TaskPresenter::class);
        $tasks = $project->tasks()->getQuery()
            ->with(TaskPresenter::WITH)
            ->whereNull('parent_id')
            ->orderByRaw('due_on is null, due_on asc')
            ->orderBy('id')
            ->limit(300)
            ->get();
        $open = $present->openDependencyMap($tasks->pluck('id')->all());
        $doneIds = $present->doneStatusIds();

        $total = $tasks->count();
        $done = $tasks->filter(fn (Task $t) => in_array($t->status_id, $doneIds, true))->count();
        $estimate = (int) $tasks->sum('estimate_minutes');
        $estimateDone = (int) $tasks->filter(fn (Task $t) => in_array($t->status_id, $doneIds, true))->sum('estimate_minutes');
        $overdue = $tasks->filter(fn (Task $t) => ! in_array($t->status_id, $doneIds, true) && $t->due_on !== null && $t->due_on->isPast() && ! $t->due_on->isToday())->count();
        $logged = (int) TimeEntry::query()->where('project_id', $project->id)->sum('minutes');

        return [
            'tasks' => $tasks->map(fn (Task $t): array => [...$present->row($t, $open), 'estimate_minutes' => $t->estimate_minutes])->values(),
            'progress' => [
                'total' => $total,
                'done' => $done,
                'overdue' => $overdue,
                'percent' => $total > 0 ? (int) round($done / $total * 100) : 0,
                'estimate_minutes' => $estimate,
                'estimate_done_minutes' => $estimateDone,
                'logged_minutes' => $logged,
                'by_status' => $tasks->groupBy('status_id')->map(fn ($group) => [
                    'name' => $group->first()?->status->name,
                    'color' => $group->first()?->status->color,
                    'count' => $group->count(),
                ])->values(),
            ],
            'taskFiles' => app(AttachmentPresenter::class)->forTasks($project->tasks()->pluck('id')->map(fn ($id) => (int) $id)->all()),
            'departments' => $this->presenter->departmentOptions(),
        ];
    }

    public function update(SaveProjectRequest $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $data = $request->validated();
        $normalized = $this->mentions->normalize($data['description'] ?? null, $workspace->id);

        DB::transaction(function () use ($project, $data, $normalized, $request) {
            $project->fill([...$this->attributes($data), 'description' => $normalized['text']])->save();
            $this->syncPeopleAndLabels($project, $data);
            $this->mentions->sync($project, $normalized['user_ids'], $request->user());
        });

        $this->activity->log('project.updated', $project, [
            'workspace_id' => $workspace->id,
            'changed' => array_keys($project->getChanges()),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project saved.')]);

        return to_route('workspaces.projects.show', [$workspace, $project]);
    }

    /**
     * B-06: turn on (or replace) the client status link. Leads only. Replacing breaks the old link.
     */
    public function statusLink(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $data = $request->validate(['expires_days' => ['nullable', 'integer', 'in:7,30,90']]);

        $project->forceFill([
            'status_token' => Str::random(40),
            'status_token_expires_at' => isset($data['expires_days']) ? now()->addDays((int) $data['expires_days']) : null,
            'status_views' => 0,
        ])->save();
        $this->activity->log('project.status-link-created', $project, ['expires_days' => $data['expires_days'] ?? null]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Client status link ready. Copy it below and send it to the client.')]);

        return back();
    }

    /** R-22: how candid the client status page is (simple wording by default). */
    public function statusDetail(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $data = $request->validate(['status_detail' => ['required', 'in:simple,detailed']]);
        $project->forceFill(['status_detail' => $data['status_detail']])->save();

        return back();
    }

    public function disableStatusLink(Workspace $workspace, Project $project): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $project->forceFill(['status_token' => null, 'status_token_expires_at' => null])->save();
        $this->activity->log('project.status-link-disabled', $project);

        return back();
    }

    public function destroy(Workspace $workspace, Project $project): RedirectResponse
    {
        Gate::authorize('lead', $workspace);

        $project->delete(); // soft delete; restorable from the database
        $this->activity->log('project.deleted', $project, ['workspace_id' => $workspace->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Project deleted.')]);

        return to_route('workspaces.projects.index', $workspace);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return [
            'name' => $data['name'],
            'type' => $data['type'],
            'status' => $data['status'],
            'lead_user_id' => $data['lead_user_id'] ?? null,
            'start_on' => $data['start_on'] ?? null,
            'due_on' => $data['due_on'] ?? null,
            // Only when the form sends it, so other callers don't switch sign-off off by accident.
            ...(array_key_exists('review_required', $data) ? ['review_required' => (bool) $data['review_required']] : []),
            ...(array_key_exists('review_sla_days', $data) ? ['review_sla_days' => (int) $data['review_sla_days']] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function syncPeopleAndLabels(Project $project, array $data): void
    {
        $memberIds = array_map('intval', $data['member_ids'] ?? []);
        if (! empty($data['lead_user_id'])) {
            $memberIds[] = (int) $data['lead_user_id']; // the lead is always on the project
        }

        $project->members()->sync(array_values(array_unique($memberIds)));
        // The form sends an empty department_ids[] so un-ticking everything clears the list (S3-02).
        if (array_key_exists('department_ids', $data)) {
            $project->departments()->sync(array_values(array_unique(array_map('intval', array_filter((array) $data['department_ids'])))));
        }
        $project->labels()->sync(array_map('intval', $data['label_ids'] ?? []));
    }
}
