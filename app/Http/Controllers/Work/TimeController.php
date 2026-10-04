<?php

namespace App\Http\Controllers\Work;

use App\Domain\Work\TimeService;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Http\Controllers\Controller;
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

        return Inertia::render('time/Timesheet', [
            'week' => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'entries' => $entries->map(fn (TimeEntry $e): array => $this->row($e))->values(),
        ]);
    }

    /**
     * Pending time in workspaces the viewer leads, for approval.
     */
    public function approvals(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $filters = $request->validate([
            'week' => ['nullable', 'date'],
            'workspace' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['pending', 'approved'])],
        ]);
        $start = TimeService::weekStart($filters['week'] ?? null);
        $end = $start->addDays(6);

        $workspaceIds = $this->leadWorkspaceIds($user);

        $entries = TimeEntry::query()
            ->with(['task:id,title', 'workspace:id,name,slug,color', 'user:id,name', 'approver:id,name'])
            ->whereIn('workspace_id', $workspaceIds)
            ->when($filters['workspace'] ?? null, fn (Builder $q, $id) => $q->where('workspace_id', (int) $id))
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
            ])->values(),
            'workspaces' => Workspace::query()->whereIn('id', $workspaceIds)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function approve(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'ids' => ['required', 'array', 'max:500'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['approve', 'unapprove'])],
        ]);

        // Only entries in workspaces this person leads.
        $ids = TimeEntry::query()
            ->whereIn('id', $data['ids'])
            ->whereIn('workspace_id', $this->leadWorkspaceIds($user))
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
            'task' => $e->task?->only(['id', 'title']),
            'workspace' => $e->workspace->only(['id', 'name', 'slug', 'color']),
        ];
    }
}
