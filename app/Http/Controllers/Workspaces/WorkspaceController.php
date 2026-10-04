<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Reports\WorkspaceOverview;
use App\Domain\Workspaces\LinkService;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Domain\Workspaces\WorkspacePresenter;
use App\Http\Controllers\Controller;
use App\Models\Project;
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

        return Inertia::render('workspaces/Show', [
            'workspace' => $this->presenter->header($workspace, $user),
            'stats' => [
                'byStatus' => $workspace->projects()->toBase()->select('status', DB::raw('count(*) as total'))
                    ->groupBy('status')->pluck('total', 'status'),
                'byType' => $workspace->projects()->toBase()->select('type', DB::raw('count(*) as total'))
                    ->groupBy('type')->pluck('total', 'type'),
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
            'details' => $workspace->only(['name', 'industry', 'website', 'status', 'color', 'description', 'primary_contact_name']),
            'labels' => $workspace->labels()->withCount('projects')->orderBy('name')->get()
                ->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color, 'uses' => $l->projects_count]),
            'colors' => Workspace::COLORS,
            'statuses' => Workspace::STATUSES,
        ]);
    }

    public function update(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('update', $workspace);

        $data = $request->validate([
            ...$this->rules(),
            'status' => ['required', Rule::in(Workspace::STATUSES)],
        ]);

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
        if ($user->last_workspace_id !== $workspace->id) {
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
