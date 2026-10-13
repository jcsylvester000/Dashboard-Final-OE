<?php

namespace App\Http\Controllers\Work;

use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Domain\People\Capacity;
use App\Domain\Work\TimeService;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Timer, manual time entries, personal weekly timesheet and lead approvals.
 */
class TimeController extends Controller
{
    public function __construct(
        private readonly TimeService $time,
        private readonly WorkspaceAccess $access,
    ) {}

    public function start(Request $request, Workspace $workspace, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);

        $this->time->start($task, $request->user());
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Timer started.')]);

        return back();
    }

    public function stop(Request $request): RedirectResponse
    {
        $entry = $this->time->running($request->user());
        if ($entry !== null) {
            $this->time->stop($entry);
            Inertia::flash('toast', ['type' => 'success', 'message' => __(':m min logged.', ['m' => $entry->minutes])]);
        }

        return back();
    }

    public function log(Request $request, Workspace $workspace, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);

        $data = $request->validate([
            'entry_date' => ['required', 'date', 'before_or_equal:today'],
            'hours' => ['nullable', 'integer', 'min:0', 'max:12'],
            'minutes' => ['nullable', 'integer', 'min:0', 'max:59'],
            'note' => ['nullable', 'string', 'max:500'],
            'is_billable' => ['boolean'],
        ]);

        $minutes = (int) ($data['hours'] ?? 0) * 60 + (int) ($data['minutes'] ?? 0);
        if ($minutes < 1 || $minutes > TimeEntry::MAX_MINUTES) {
            return back()->withErrors(['minutes' => __('Enter between 1 minute and 12 hours.')]);
        }

        $this->time->log($task, $request->user(), [
            'entry_date' => $data['entry_date'],
            'minutes' => $minutes,
            'note' => $data['note'] ?? null,
            'is_billable' => $request->boolean('is_billable', true),
        ]);

        return back();
    }

    public function update(Request $request, TimeEntry $entry): RedirectResponse
    {
        $this->guardOwnEditable($request->user(), $entry);

        $data = $request->validate([
            'entry_date' => ['sometimes', 'date', 'before_or_equal:today'],
            'minutes' => ['sometimes', 'integer', 'min:1', 'max:'.TimeEntry::MAX_MINUTES],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
            'is_billable' => ['sometimes', 'boolean'],
        ]);

        $this->time->update($entry, $data);

        return back();
    }

    public function destroy(Request $request, TimeEntry $entry): RedirectResponse
    {
        $this->guardOwnEditable($request->user(), $entry);

        $this->time->delete($entry);

        return back();
    }

    /**
     * My week: entries grouped by task, one column per day.
     */
    public function timesheet(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $week = $request->validate(['week' => ['nullable', 'date']])['week'] ?? null;
        $start = TimeService::weekStart(is_string($week) ? $week : null);
        $end = $start->addDays(6);

        $entries = TimeEntry::query()
            ->with(['task:id,title,workspace_id', 'workspace:id,name,slug,color'])
            ->where('user_id', $user->id)
            ->whereHas('workspace')
            ->whereDate('entry_date', '>=', $start->toDateString())
            ->whereDate('entry_date', '<=', $end->toDateString())
            ->orderBy('entry_date')
            ->get();

        // Tasks I can log against from the grid: assigned to me (open) or already in this week.
        $taskIds = $entries->pluck('task_id')->filter()->unique()->values()->all();
        $tasks = Task::query()->with('workspace:id,name,slug')
            ->where(fn (Builder $q) => $q->where(fn (Builder $q) => $q->where('assignee_id', $user->id)->whereNull('completed_at'))->orWhereIn('id', $taskIds))
            ->whereHas('workspace', fn (Builder $q) => $q->where('status', 'active'))
            ->orderBy('title')->limit(200)->get(['id', 'title', 'workspace_id']);

        $open = $entries->filter(fn (TimeEntry $e) => $e->approved_at === null && ! $e->isRunning());

        return Inertia::render('time/Timesheet', [
            'week' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'entries' => $entries->map(fn (TimeEntry $e): array => $this->row($e))->values(),
            // 8h weekdays minus holidays and approved leave (B-14).
            'capacityMinutes' => app(Capacity::class)->load($start->toDateString(), $end->toDateString(), [$user->id])->minutes($user->id),
            'holidays' => Capacity::holidays($start->toDateString(), $end->toDateString()),
            'status' => match (true) {
                $entries->isEmpty() => 'empty',
                $entries->contains(fn (TimeEntry $e) => $e->returned_at !== null) => 'returned',
                $open->isEmpty() => 'approved',
                $open->every(fn (TimeEntry $e) => $e->submitted_at !== null) => 'submitted',
                default => 'draft',
            },
            'tasks' => $tasks->map(fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'workspace' => $t->workspace->only(['name', 'slug'])])->values(),
        ]);
    }

    /**
     * Pending time the viewer may approve (workspace lead, or department lead), for approval.
     */
    public function approvals(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $filters = $request->validate([
            'week' => ['nullable', 'date'],
            'workspace' => ['nullable', 'integer'],
            'project' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['pending', 'approved'])],
        ]);
        $start = TimeService::weekStart($filters['week'] ?? null);
        $end = $start->addDays(6);

        $workspaceIds = $this->approvableWorkspaceIds($user);
        abort_if($workspaceIds === [], 403, 'You don\'t approve time for any client.');

        $entries = $this->approvable($user)
            ->with(['task:id,title', 'workspace:id,name,slug,color', 'user:id,name', 'approver:id,name', 'department:id,name'])
            ->when($filters['workspace'] ?? null, fn (Builder $q, $id) => $q->where('workspace_id', (int) $id))
            ->when($filters['project'] ?? null, fn (Builder $q, $id) => $q->where('project_id', (int) $id))
            ->whereDate('entry_date', '>=', $start->toDateString())
            ->whereDate('entry_date', '<=', $end->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'))
            ->when(($filters['status'] ?? 'pending') === 'pending', fn (Builder $q) => $q->whereNull('approved_at'), fn (Builder $q) => $q->whereNotNull('approved_at'))
            ->orderBy('entry_date')
            ->limit(500)
            ->get();

        return Inertia::render('time/Approvals', [
            'week' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'filters' => [...$filters, 'status' => $filters['status'] ?? 'pending'],
            'entries' => $entries->map(fn (TimeEntry $e): array => [
                ...$this->row($e),
                'user' => $e->user->only(['id', 'name']),
                'approver' => $e->approver?->name,
                'is_mine' => $e->user_id === $user->id,
                'department' => $e->department?->name,
            ])->values(),
            'workspaces' => Workspace::query()->whereIn('id', $workspaceIds)->orderBy('name')->get(['id', 'name']),
            'projects' => Project::query()
                ->whereIn('workspace_id', ($filters['workspace'] ?? null) ? [(int) $filters['workspace']] : $workspaceIds)
                ->where('status', '!=', 'archived')
                ->orderBy('name')
                ->limit(200)
                ->get(['id', 'name']),
        ]);
    }

    public function approve(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        // Same rule as the Approvals page: people who approve nobody's time get a 403.
        abort_if($this->approvableWorkspaceIds($user) === [], 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['approve', 'unapprove'])],
        ]);

        // Only entries this person may approve (workspace lead, or department lead for their department).
        $ids = $this->approvable($user)
            ->whereIn('id', $data['ids'])
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $count = $data['action'] === 'approve'
            ? $this->time->approve($ids, $user)
            : $this->time->unapprove($ids, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':n entries updated.', ['n' => $count])]);

        return back();
    }

    /** Submit my week for approval. */
    public function submitWeek(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $week = $request->validate(['week' => ['required', 'date_format:Y-m-d']])['week'];
        $count = $this->time->submitWeek($user, TimeService::weekStart($week));

        Inertia::flash('toast', ['type' => $count > 0 ? 'success' : 'info', 'message' => $count > 0
            ? trans_choice(':n entry submitted for approval.|:n entries submitted for approval.', $count, ['n' => $count])
            : __('Nothing to submit this week.')]);

        return back();
    }

    /** Send entries back to their author with a reason. */
    public function returnEntries(Request $request, Notifier $notifier): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        // Same rule as the Approvals page: people who approve nobody's time get a 403.
        abort_if($this->approvableWorkspaceIds($user) === [], 403);
        $data = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $ids = $this->approvable($user)->whereIn('id', $data['ids'])->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $returned = $this->time->returnEntries($ids, $user, $data['reason']);

        // One alert per person per client.
        foreach ($returned->groupBy(fn (TimeEntry $e) => $e->user_id.':'.$e->workspace_id) as $group) {
            $first = $group->first();
            if ($first === null) {
                continue;
            }
            $notifier->send([$first->user_id], NotificationKind::Timesheet, $first->workspace, [
                'title' => __(':name returned :n time entries', ['name' => $user->name, 'n' => $group->count()]),
                'body' => $data['reason'],
                'url' => route('time.timesheet', ['week' => TimeService::weekStart($first->entry_date->toDateString())->toDateString()], false),
            ], $user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice(':n entry returned with your note.|:n entries returned with your note.', $returned->count(), ['n' => $returned->count()])]);

        return back();
    }

    /**
     * Only the author, only while they still contribute to the workspace, never a running timer.
     */
    private function guardOwnEditable(User $user, TimeEntry $entry): void
    {
        abort_unless($entry->user_id === $user->id, 403);
        abort_if($entry->isRunning(), 422, 'Stop the timer first.');
        abort_unless($entry->workspace !== null && $this->access->canContribute($user, $entry->workspace), 403);
    }

    /**
     * Time entries this person may approve: everything in workspaces they lead, plus
     * their own department's entries in any workspace they can see (department leads).
     *
     * @return Builder<TimeEntry>
     */
    private function approvable(User $user): Builder
    {
        $leadWorkspaces = $this->leadWorkspaceIds($user);
        $departments = $this->ledDepartmentIds($user);
        $visible = $departments === [] ? [] : Workspace::query()->visibleTo($user)->pluck('id')->map(fn ($id) => (int) $id)->all();

        return TimeEntry::query()->where(fn (Builder $q) => $q
            ->whereIn('workspace_id', $leadWorkspaces)
            ->orWhere(fn (Builder $q) => $q->whereIn('department_id', $departments)->whereIn('workspace_id', $visible)));
    }

    /**
     * @return list<int>
     */
    private function approvableWorkspaceIds(User $user): array
    {
        $ids = $this->leadWorkspaceIds($user);
        if ($this->ledDepartmentIds($user) !== []) {
            $ids = [...$ids, ...Workspace::query()->visibleTo($user)->pluck('id')->map(fn ($id) => (int) $id)->all()];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<int>
     */
    private function ledDepartmentIds(User $user): array
    {
        return Department::query()->where('lead_user_id', $user->id)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * @return list<int>
     */
    private function leadWorkspaceIds(User $user): array
    {
        return Workspace::query()->visibleTo($user)->get(['id', 'status'])
            ->filter(fn (Workspace $w) => $this->access->canLead($user, $w) || $this->access->canOwn($user, $w))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(TimeEntry $e): array
    {
        return [
            'id' => $e->id,
            'entry_date' => $e->entry_date->toDateString(),
            'minutes' => $e->minutes,
            'note' => $e->note,
            'is_billable' => $e->is_billable,
            'running' => $e->isRunning(),
            'approved' => $e->approved_at !== null,
            'locked' => $e->isLocked(),
            'submitted' => $e->submitted_at !== null,
            'returned' => $e->returned_at !== null,
            'return_reason' => $e->return_reason,
            'task' => $e->task?->only(['id', 'title']),
            'workspace' => $e->workspace->only(['id', 'name', 'slug', 'color']),
        ];
    }
}
