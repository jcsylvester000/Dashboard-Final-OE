<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Identity\Permissions;
use App\Domain\Reports\SnapshotService;
use App\Domain\Workspaces\WorkspacePresenter;
use App\Http\Controllers\Controller;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Weekly report snapshots for one workspace, kept in-app for its leads.
 */
class ReportSnapshotController extends Controller
{
    public function index(Request $request, Workspace $workspace, WorkspacePresenter $presenter): Response
    {
        $this->authorizeReports($request, $workspace);

        $snapshots = ReportSnapshot::query()
            ->where('workspace_id', $workspace->id)
            ->latest('week_start')
            ->limit(52)
            ->get();

        $selectedId = (int) $request->integer('snapshot');
        $selected = $snapshots->firstWhere('id', $selectedId) ?? $snapshots->first();

        return Inertia::render('workspaces/Reports', [
            'workspace' => $presenter->header($workspace, $request->user()),
            'snapshots' => $snapshots->map(fn (ReportSnapshot $s): array => [
                'id' => $s->id,
                'week_start' => $s->week_start->toDateString(),
                'completed' => (int) ($s->data['completed'] ?? 0),
                'hours' => (float) ($s->data['hours'] ?? 0),
            ])->values(),
            'selected' => $selected ? ['id' => $selected->id, 'data' => $selected->data, 'saved_at' => $selected->updated_at?->toIso8601String()] : null,
            'canCapture' => Gate::allows('lead', $workspace) || $request->user()->can(Permissions::REPORTS_VIEW_ALL),
        ]);
    }

    public function store(Request $request, Workspace $workspace, SnapshotService $snapshots): RedirectResponse
    {
        $this->authorizeReports($request, $workspace);

        $week = $request->validate(['week' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']])['week'] ?? null;
        $start = $week !== null ? CarbonImmutable::parse($week) : SnapshotService::lastWeek();

        /** @var User $user */
        $user = $request->user();
        $snapshot = $snapshots->capture($workspace, $start, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Snapshot saved.')]);

        return to_route('workspaces.reports.index', [$workspace, 'snapshot' => $snapshot->id]);
    }

    private function authorizeReports(Request $request, Workspace $workspace): void
    {
        /** @var User $user */
        $user = $request->user();
        Gate::authorize('view', $workspace);
        abort_unless(Gate::allows('lead', $workspace) || $user->can(Permissions::REPORTS_VIEW_ALL), 403);
    }
}
