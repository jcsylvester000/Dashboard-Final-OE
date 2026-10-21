<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Billing\PrepaidHoursService;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Identity\Permissions;
use App\Domain\Identity\ViewAs;
use App\Domain\Reports\WorkspaceOverview;
use App\Domain\Work\ChecklistDefaults;
use App\Domain\Workspaces\LinkService;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Domain\Workspaces\WorkspacePresenter;
use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\ChatChannel;
use App\Models\CodeRepository;
use App\Models\DeliverySpec;
use App\Models\Department;
use App\Models\Project;
use App\Models\ReportPack;
use App\Models\ReviewRule;
use App\Models\Task;
use App\Models\TaskStatus;
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

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly WorkspaceAccess $access,
        private readonly WorkspacePresenter $presenter,
        private readonly ActivityLogger $activity,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $showArchived = $request->boolean('archived');

        $workspaces = Workspace::query()
            ->visibleTo($user)
            ->when(! $showArchived, fn (Builder $q) => $q->where('status', '!=', 'archived'))
            ->withCount([
                'members',
                'projects as open_projects_count' => fn (Builder $q) => $q->whereNotIn('status', ['completed', 'archived']),
            ])
            ->orderBy('name')
            ->limit(500) // E-122
            ->get()
            ->map(fn (Workspace $w): array => [
                'id' => $w->id,
                'name' => $w->name,
                'slug' => $w->slug,
                'industry' => $w->industry,
                'status' => $w->status,
                'color' => $w->color,
                'members' => $w->members_count,
                'openProjects' => $w->open_projects_count,
                'myRole' => $this->access->role($user, $w),
            ]);

        return Inertia::render('workspaces/Index', [
            'workspaces' => $workspaces,
            'showArchived' => $showArchived,
            'canCreate' => Gate::allows('create', Workspace::class),
            'colors' => Workspace::COLORS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Workspace::class);

        $data = $request->validate($this->rules());

        $workspace = DB::transaction(function () use ($data, $request) {
            $workspace = Workspace::create([
                ...$data,
                'slug' => $this->uniqueSlug($data['name']),
                'status' => 'active',
                'created_by' => $request->user()->id,
            ]);
            // The creator owns the new workspace.
            $workspace->members()->attach($request->user()->id, ['role' => Workspace::ROLE_OWNER]);

            return $workspace;
        });

        $this->activity->log('workspace.created', $workspace);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace created.')]);

        return to_route('workspaces.show', $workspace);
    }

    public function show(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);

        /** @var User $user */
        $user = $request->user();
        $this->remember($user, $workspace);

        // E-74: prepaid / rolled-over hours left (hours only) for leads and Finance.
        $seePrepaid = $user->can(Permissions::BILLING_VIEW) || app(WorkspaceAccess::class)->canLead($user, $workspace);

        return Inertia::render('workspaces/Show', [
            'workspace' => $this->presenter->header($workspace, $user),
            'prepaid' => $seePrepaid ? array_values(array_filter(app(PrepaidHoursService::class)->summary($workspace->id, false),
                fn (array $p) => $p['left_minutes'] > 0 && ! $p['expired'])) : null,
            'stats' => [
                'byStatus' => $workspace->projects()->toBase()->select('status', DB::raw('count(*) as total'))
                    ->groupBy('status')->pluck('total', 'status'),
                'byType' => $workspace->projects()->toBase()->select('type', DB::raw('count(*) as total'))
                    ->groupBy('type')->pluck('total', 'type'),
                // S1-02: the first card counts tasks, not projects.
                'openTasks' => $workspace->tasks()->whereNull('completed_at')->count(),
                'overdueTasks' => $workspace->tasks()->whereNull('completed_at')->whereDate('due_on', '<', now()->toDateString())->count(),
            ],
            'upcoming' => $workspace->projects()
                ->with('lead:id,name')
                ->whereNotIn('status', ['completed', 'archived'])
                ->orderByRaw('due_on is null, due_on asc')
                ->limit(6)
                ->get()
                ->map(fn (Project $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'type' => $p->type,
                    'status' => $p->status,
                    'lead' => $p->lead?->name,
                    'due_on' => $p->due_on?->toDateString(),
                ]),
            'members' => $workspace->members()
                ->orderBy('name')
                ->limit(24)
                ->get(['users.id', 'users.name', 'users.title'])
                ->map(fn (User $m) => [
                    'id' => $m->id,
                    'name' => $m->name,
                    'title' => $m->title,
                    'role' => $m->getRelation('membership')->getAttribute('role'),
                ]),
            'links' => app(LinkService::class)->linksFor($workspace, $user),
            'overview' => app(WorkspaceOverview::class)->build($workspace),
            'projectTypes' => Project::TYPES,
            'projectStatuses' => Project::STATUSES,
        ]);
    }

    public function edit(Request $request, Workspace $workspace): Response
    {
        // Owners edit details; leads manage labels on the same page.
        abort_unless(Gate::allows('update', $workspace) || Gate::allows('lead', $workspace), 403);

        return Inertia::render('workspaces/Settings', [
            'workspace' => $this->presenter->header($workspace, $request->user()),
            'details' => $workspace->only(['name', 'key', 'industry', 'website', 'status', 'color', 'description', 'primary_contact_name']),
            'labels' => $workspace->labels()->withCount('projects')->orderBy('name')->get()
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color, 'uses' => $l->projects_count]),
            'colors' => Workspace::COLORS,
            'statuses' => Workspace::STATUSES,
            // B-07: custom task fields and this client's status names.
            'customFields' => $workspace->custom_fields ?? [],
            'fieldTypes' => Workspace::CUSTOM_FIELD_TYPES,
            'maxFields' => Workspace::MAX_CUSTOM_FIELDS,
            'checklistTemplates' => collect(Task::TYPES)->mapWithKeys(fn ($label, $type) => [$type => implode("\n", $workspace->checklist_templates[$type] ?? [])]),
            // E-25: the agency default a type falls back to when this client has none.
            'checklistDefaults' => collect(app(ChecklistDefaults::class)->all())->map(fn (array $lines) => implode("\n", $lines)),
            // E-34: review rules.
            'reviewRules' => ReviewRule::query()->with('project:id,name')->where('workspace_id', $workspace->id)->orderBy('id')->get()
                ->map(fn (ReviewRule $r): array => [
                    'id' => $r->id, 'project' => $r->project?->name, 'deliverable_type' => $r->deliverable_type, 'task_type' => $r->task_type,
                    'mode' => $r->mode, 'reviewers' => User::query()->whereIn('id', $r->user_ids)->orderBy('name')->pluck('name')->values(),
                ])->values(),
            'reviewModes' => ReviewRule::MODES,
            // E-18: delivery specs (leads).
            'deliverySpecs' => DeliverySpec::query()->where('workspace_id', $workspace->id)->orderBy('name')->get()
                ->map(fn (DeliverySpec $d): array => $d->only(['id', 'name', 'channel', 'deliverable', 'aspect', 'min_width', 'min_height', 'max_duration_s', 'max_size_mb', 'captions_required']))->values(),
            'channelOptions' => Task::WORK_DETAIL_FIELDS['marketing']['channel'][2],
            // E-24 / E-41: GitHub repositories and chat channels.
            'repositories' => CodeRepository::query()->where('workspace_id', $workspace->id)->orderBy('full_name')->get(['id', 'full_name', 'on_open_status', 'on_merge_status']),
            'githubWebhook' => ['url' => route('webhooks.github'), 'configured' => (string) config('services.github.webhook_secret') !== ''],
            'chatChannels' => ChatChannel::query()->where('workspace_id', $workspace->id)->orderBy('id')->get()
                ->map(fn (ChatChannel $c): array => [
                    'id' => $c->id, 'provider' => $c->provider, 'name' => $c->name, 'url' => $c->maskedUrl(), 'events' => $c->events,
                    'enabled' => $c->enabled, 'last_ok_at' => $c->last_ok_at?->toIso8601String(), 'last_error' => $c->last_error,
                ])->values(),
            'chatEvents' => ChatChannel::EVENTS,
            // E-37 / E-51: automation rules (with their latest runs) and report packs.
            'automations' => AutomationRule::query()->where('workspace_id', $workspace->id)->with(['runLog' => fn ($q) => $q->limit(200)])->orderBy('id')->get()
                ->map(fn (AutomationRule $r): array => [
                    'id' => $r->id, 'name' => $r->name, 'trigger' => $r->trigger, 'trigger_value' => $r->trigger_value, 'conditions' => $r->conditions,
                    'actions' => $r->actions, 'enabled' => $r->enabled, 'runs' => $r->runs,
                    'log' => $r->runLog->take(5)->map(fn (AutomationRun $run) => ['outcome' => $run->outcome, 'detail' => $run->detail, 'at' => $run->created_at->toIso8601String()])->values(),
                ])->values(),
            'automationTriggers' => AutomationRule::TRIGGERS,
            'automationActions' => AutomationRule::ACTIONS,
            'statusSlugs' => TaskStatus::ordered()->pluck('name', 'slug'),
            'labelOptions' => $workspace->labels()->orderBy('name')->get(['id', 'name']),
            'departmentOptions' => Department::query()->active()->orderBy('position')->get(['id', 'name']),
            'reportPacks' => ReportPack::query()->where('workspace_id', $workspace->id)->orderBy('id')->get()
                ->map(fn (ReportPack $p): array => [
                    'id' => $p->id, 'name' => $p->name, 'sections' => $p->sections, 'frequency' => $p->frequency, 'day' => $p->day,
                    'recipient_ids' => $p->recipient_ids, 'enabled' => $p->enabled, 'next_run_on' => $p->next_run_on->toDateString(),
                    'last_run_at' => $p->last_run_at?->toIso8601String(), 'runs' => $p->runs,
                ])->values(),
            'packSections' => ReportPack::SECTIONS,
            'deliverableTypes' => Task::WORK_DETAIL_FIELDS['marketing']['deliverable_type'][2],
            'projectOptions' => $workspace->projects()->where('status', '!=', 'archived')->orderBy('name')->get(['id', 'name']),
            'memberOptions' => $this->presenter->memberOptions($workspace),
            'taskTypes' => Task::TYPES,
            'taskStatuses' => TaskStatus::ordered()->map(fn (TaskStatus $s) => [
                'id' => $s->id, 'name' => $s->name, 'label' => $workspace->status_labels[$s->id] ?? '',
            ])->values(),
        ]);
    }

    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('update', $workspace);

        if ($request->filled('key')) {
            $request->merge(['key' => strtoupper(trim((string) $request->input('key')))]);
        }

        $data = $request->validate([
            ...$this->rules(),
            'status' => ['required', Rule::in(Workspace::STATUSES)],
            // Task key prefix (BRW-24). Changing it renames every key; old short links stop working.
            'key' => ['sometimes', 'required', 'string', 'regex:/^[A-Z][A-Z0-9]{1,5}$/', Rule::unique('workspaces', 'key')->ignore($workspace->id)],
        ], ['key.regex' => __('Use 2-6 letters or digits, starting with a letter (e.g. BRW).')]);

        $workspace->fill($data)->save();

        $this->activity->log('workspace.updated', $workspace, ['changed' => array_keys($workspace->getChanges())]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Workspace saved.')]);

        return back();
    }

    /**
     * Remember the last workspace for the switcher.
     */
    private function remember(User $user, Workspace $workspace): void
    {
        if ($user->last_workspace_id !== $workspace->id && ! ViewAs::active()) {
            $user->forceFill(['last_workspace_id' => $workspace->id])->saveQuietly();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'industry' => ['nullable', 'string', 'max:80'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'color' => ['required', Rule::in(Workspace::COLORS)],
            'description' => ['nullable', 'string', 'max:2000'],
            'primary_contact_name' => ['nullable', 'string', 'max:120'],
        ];
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'workspace';
        $slug = $base;
        $i = 2;
        while (Workspace::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
