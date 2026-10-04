<?php

namespace App\Domain\Reports;

use App\Models\ReportSnapshot;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Weekly report snapshot per workspace: what got done, time logged and what is
 * still open, frozen so leads can look back week by week. Re-capturing a week
 * replaces that week's snapshot.
 */
class SnapshotService
{
    public function __construct(private readonly ReportService $reports) {}

    /**
     * Monday of the last full week.
     */
    public static function lastWeek(): CarbonImmutable
    {
        return now()->toImmutable()->subWeek()->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    public function capture(Workspace $workspace, CarbonImmutable $weekStart, ?User $by = null): ReportSnapshot
    {
        $from = $weekStart->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
        $to = $from->addDays(6)->endOfDay();
        $filters = new ReportFilters(null, $from, $to, $workspace->id);

        $doneIds = TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id')->all();
        $live = fn () => DB::table('tasks')->where('workspace_id', $workspace->id)->whereNull('deleted_at');

        $time = $this->reports->time($filters, 'department');
        $people = $this->reports->time($filters, 'person');

        $completed = Task::query()
            ->with(['assignee:id,name', 'department:id,name'])
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$from, $to])
            ->orderBy('completed_at')
            ->limit(100)
            ->get();

        $data = [
            'week_start' => $from->toDateString(),
            'week_end' => $to->toDateString(),
            'completed' => $live()->whereNotNull('completed_at')->whereBetween('completed_at', [$from, $to])->count(),
            'created' => $live()->whereBetween('created_at', [$from, $to])->count(),
            'open' => $live()->whereNotIn('status_id', $doneIds)->count(),
            'overdue' => $live()->whereNotIn('status_id', $doneIds)->whereDate('due_on', '<', now()->toDateString())->count(),
            'hours' => $time['summary']['Hours'],
            'billable_hours' => $time['summary']['Billable hours'],
            'time_by_department' => array_map(fn (array $r) => ['name' => $r['name'], 'hours' => $r['hours'], 'billable_hours' => $r['billable_hours']], $time['rows']),
            'time_by_person' => array_map(fn (array $r) => ['name' => $r['name'], 'hours' => $r['hours'], 'billable_hours' => $r['billable_hours']], $people['rows']),
            'completed_tasks' => $completed->map(fn (Task $t): array => [
                'id' => $t->id,
                'title' => $t->title,
                'department' => $t->department?->name,
                'assignee' => $t->assignee?->name,
                'completed_on' => $t->completed_at?->toDateString(),
            ])->values()->all(),
        ];

        $snapshot = ReportSnapshot::query()
            ->where('workspace_id', $workspace->id)
            ->whereDate('week_start', $from->toDateString())
            ->first() ?? new ReportSnapshot(['workspace_id' => $workspace->id, 'week_start' => $from->toDateString()]);

        $snapshot->fill(['data' => $data, 'created_by' => $by?->id])->save();

        return $snapshot;
    }
}
