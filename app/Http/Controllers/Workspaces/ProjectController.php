<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Files\AttachmentPresenter;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Workspaces\LinkService;
use App\Domain\Workspaces\MentionService;
use App\Domain\Workspaces\WorkspacePresenter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\SaveProjectRequest;
use App\Models\Label;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
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
            ->withCount('members')
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
            ->withQueryString()
            ->through(fn (Project $p): array => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type,
                'status' => $p->status,
                'lead' => $p->lead?->name,
                'members' => $p->members_count,
                'labels' => $p->labels->map(fn (Label $l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values(),
                'start_on' => $p->start_on?->toDateString(),
                'due_on' => $p->due_on?->toDateString(),
            ]);

        return Inertia::render('projects/Index', [
            'workspace' => $this->presenter->header($workspace, $user),
            'projects' => $projects,
            'filters' => $filters,
            'types' => Project::TYPES,
            'statuses' => Project::STATUSES,
            'labels' => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
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
        $project->load(['lead:id,name', 'members:id,name,title', 'labels:id,name,color', 'mentions.user:id,name']);
        $project->setRelation('workspace', $workspace);

        if ($user->last_workspace_id !== $workspace->id) {
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
                'lead' => $project->lead?->name,
                'start_on' => $project->start_on?->toDateString(),
                'due_on' => $project->due_on?->toDateString(),
                'members' => $project->members->map(fn (User $m) => ['id' => $m->id, 'name' => $m->name, 'title' => $m->title])->values(),
                'labels' => $project->labels->map(fn (Label $l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->values(),
                'mentioned' => $project->mentions->map(fn ($m) => $m->user?->name)->filter()->values(),
                'created_at' => $project->created_at?->toIso8601String(),
                'updated_at' => $project->updated_at?->toIso8601String(),
            ],
            'links' => $links->linksFor($project, $user),
            'types' => Project::TYPES,
            'statuses' => Project::STATUSES,
            'labels' => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
            'members' => $this->presenter->memberOptions($workspace),
            'canDelete' => Gate::allows('lead', $workspace),
            'attachments' => app(AttachmentPresenter::class)->for($project, $workspace, $user),
            'fileLimits' => app(AttachmentPresenter::class)->limits(),
        ]);
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
        $project->labels()->sync(array_map('intval', $data['label_ids'] ?? []));
    }
}
