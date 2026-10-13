<?php

namespace App\Http\Controllers\Work;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Work\SprintService;
use App\Domain\Work\TaskPresenter;
use App\Domain\Workspaces\WorkspacePresenter;
use App\Http\Controllers\Controller;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sprint planning page: active sprint with burndown, planned sprints, backlog
 * and the report of closed sprints (velocity).
 * Leads create, start and close sprints; contributors move tasks and set points.
 */
class SprintController extends Controller
{
    public function __construct(
        private readonly SprintService $sprints,
        private readonly TaskPresenter $present,
        private readonly WorkspacePresenter $workspaces,
    ) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);
        /** @var User $user */
        $user = $request->user();

        $open = $workspace->sprints()->with('project:id,name')->where('status', '!=', Sprint::CLOSED)
            ->orderByRaw("case when status = 'active' then 0 else 1 end")->orderBy('starts_on')->get();

        $taskQuery = fn () => Task::query()->with(TaskPresenter::WITH)->where('workspace_id', $workspace->id)->whereNull('parent_id');

        $sprintTasks = $taskQuery()->whereIn('sprint_id', $open->pluck('id'))->orderBy('completed_at')->orderBy('position')->get();
        $backlog = $taskQuery()->whereNull('sprint_id')->whereNull('completed_at')
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->orderBy('id')->limit(200)->get();

        $deps = $this->present->openDependencyMap([...$sprintTasks->pluck('id')->all(), ...$backlog->pluck('id')->all()]);
        $row = fn (Task $t): array => $this->present->row($t, $deps);

        $active = $open->firstWhere('status', Sprint::ACTIVE);

        return Inertia::render('sprints/Index', [
            'workspace' => $this->workspaces->header($workspace, $user),
            'sprints' => $open->map(function (Sprint $s) use ($sprintTasks, $row) {
                $tasks = $sprintTasks->where('sprint_id', $s->id);

                return [
                    ...$this->sprintRow($s),
                    'points' => (int) $tasks->sum('story_points'),
                    'done_points' => (int) $tasks->whereNotNull('completed_at')->sum('story_points'),
                    'done_count' => $tasks->whereNotNull('completed_at')->count(),
                    'tasks' => $tasks->map($row)->values(),
                ];
            })->values(),
            'burndown' => $active !== null ? $this->sprints->burndown($active) : null,
            'backlog' => $backlog->map($row)->values(),
            'closed' => $workspace->sprints()->where('status', Sprint::CLOSED)->latest('closed_at')->limit(8)->get()
                ->map(fn (Sprint $s) => $this->sprintRow($s))->values(),
            'projects' => $this->present->formOptions($workspace)['projects'],
            'pointScale' => Sprint::POINTS,
            'canManage' => Gate::allows('lead', $workspace),
            'canPlan' => Gate::allows('contribute', $workspace),
        ]);
    }

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('lead', $workspace);

        $sprint = new Sprint([...$this->validated($request, $workspace), 'workspace_id' => $workspace->id, 'created_by' => $request->user()->id]);
        $sprint->save();
        app(ActivityLogger::class)->log('sprint.created', $sprint, ['workspace_id' => $workspace->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name planned. Move tasks into it from the backlog.', ['name' => $sprint->name])]);

        return back();
    }

    public function update(Request $request, Workspace $workspace, Sprint $sprint): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_if($sprint->isClosed(), 422, 'Closed sprints are read-only.');

        $sprint->fill($this->validated($request, $workspace))->save();

        return back();
    }

    public function start(Request $request, Workspace $workspace, Sprint $sprint): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $this->sprints->start($sprint, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name started with :n points committed.', ['name' => $sprint->name, 'n' => (int) $sprint->committed_points])]);

        return back();
    }

    public function close(Request $request, Workspace $workspace, Sprint $sprint): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $data = $request->validate(['next_sprint_id' => ['nullable', 'integer', Rule::exists('sprints', 'id')->where('workspace_id', $workspace->id)]]);
        $next = isset($data['next_sprint_id']) ? Sprint::query()->findOrFail($data['next_sprint_id']) : null;

        $this->sprints->close($sprint, $request->user(), $next);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name closed: :done of :committed points done, :carried tasks carried over to :to.', [
            'name' => $sprint->name,
            'done' => (int) $sprint->completed_points,
            'committed' => (int) $sprint->committed_points,
            'carried' => (int) $sprint->carried_over,
            'to' => $next !== null ? $next->name : __('the backlog'),
        ])]);

        return back();
    }

    public function destroy(Request $request, Workspace $workspace, Sprint $sprint): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_unless($sprint->status === Sprint::PLANNED, 422, 'Only planned sprints can be deleted.');

        // Its tasks go back to the backlog (FK nulls on delete).
        $sprint->delete();

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Workspace $workspace): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'goal' => ['nullable', 'string', 'max:500'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('workspace_id', $workspace->id)],
        ]);

        if (CarbonImmutable::parse($data['starts_on'])->diffInDays(CarbonImmutable::parse($data['ends_on'])) > 56) {
            throw ValidationException::withMessages(['ends_on' => __('Keep sprints to 8 weeks or less.')]);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function sprintRow(Sprint $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'goal' => $s->goal,
            'status' => $s->status,
            'starts_on' => $s->starts_on->toDateString(),
            'ends_on' => $s->ends_on->toDateString(),
            'project' => $s->relationLoaded('project') ? $s->project?->only(['id', 'name']) : null,
            'committed_points' => $s->committed_points,
            'completed_points' => $s->completed_points,
            'carried_over' => $s->carried_over,
        ];
    }
}
