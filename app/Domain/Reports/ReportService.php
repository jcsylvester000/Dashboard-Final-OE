<?php

namespace App\Domain\Reports;

use App\Models\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Agency reports. Every report returns the same shape so one table component
 * and one CSV exporter handle all of them:
 *   columns: list of {key, label, type}  (type: text | number | days | hours | percent)
 *   rows:    list of key => value
 *   summary: short headline numbers
 *
 * Aggregation is done in PHP over narrow selects so it behaves the same on
 * PostgreSQL and the SQLite test database.
 */
class ReportService
{
    /** report key => label */
    public const REPORTS = [
        'throughput' => 'Throughput (done per week)',
        'cycle_time' => 'Cycle time',
        'overdue' => 'Overdue by department',
        'workload' => 'Workload by person',
        'handoffs' => 'Handoff wait time',
        'time' => 'Time logged',
    ];

    /** time report grouping => label */
    public const TIME_GROUPS = [
        'workspace' => 'Workspace',
        'project' => 'Project',
        'task' => 'Task',
        'person' => 'Person',
        'department' => 'Department',
    ];

    /**
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function run(string $report, ReportFilters $f, string $group = 'person'): array
    {
        return match ($report) {
            'throughput' => $this->throughput($f),
            'cycle_time' => $this->cycleTime($f),
            'overdue' => $this->overdue($f),
            'workload' => $this->workload($f),
            'handoffs' => $this->handoffs($f),
            'time' => $this->time($f, $group),
            default => abort(404),
        };
    }

    /**
     * Tasks finished per week (Monday start), including empty weeks.
     *
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function throughput(ReportFilters $f): array
    {
        $weeks = [];
        for ($w = $f->from->startOfWeek(CarbonImmutable::MONDAY); $w->lte($f->to); $w = $w->addWeek()) {
            $weeks[$w->toDateString()] = 0;
        }

        $dates = $this->tasks($f)
            ->whereNotNull('tasks.completed_at')
            ->whereBetween('tasks.completed_at', [$f->from, $f->to])
            ->pluck('tasks.completed_at');

        foreach ($dates as $at) {
            $key = CarbonImmutable::parse((string) $at)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            if (array_key_exists($key, $weeks)) {
                $weeks[$key]++;
            }
        }

        $rows = [];
        foreach ($weeks as $week => $count) {
            $rows[] = ['week' => $week, 'completed' => $count];
        }

        $total = array_sum($weeks);

        return [
            'columns' => [
                $this->col('week', 'Week starting', 'text'),
                $this->col('completed', 'Tasks done', 'number'),
            ],
            'rows' => $rows,
            'summary' => [
                'Tasks done' => $total,
                'Per week (avg)' => count($weeks) > 0 ? round($total / count($weeks), 1) : 0,
            ],
        ];
    }

    /**
     * Days from created to done, for tasks finished in the range, by department.
     *
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function cycleTime(ReportFilters $f): array
    {
        $tasks = $this->tasks($f)
            ->whereNotNull('tasks.completed_at')
            ->whereBetween('tasks.completed_at', [$f->from, $f->to])
            ->get(['tasks.department_id', 'tasks.created_at', 'tasks.completed_at']);

        $byDept = [];
        $all = [];
        foreach ($tasks as $t) {
            $days = $this->days((string) $t->created_at, (string) $t->completed_at);
            $byDept[(int) $t->department_id][] = $days;
            $all[] = $days;
        }

        $names = $this->departmentNames();
        $rows = [];
        foreach ($byDept as $deptId => $values) {
            $rows[] = [
                'department' => $names[$deptId] ?? 'No department',
                'completed' => count($values),
                'avg_days' => $this->avg($values),
                'median_days' => $this->median($values),
            ];
        }
        usort($rows, fn (array $a, array $b) => strcmp((string) $a['department'], (string) $b['department']));

        return [
            'columns' => [
                $this->col('department', 'Department', 'text'),
                $this->col('completed', 'Tasks done', 'number'),
                $this->col('avg_days', 'Average days', 'days'),
                $this->col('median_days', 'Median days', 'days'),
            ],
            'rows' => $rows,
            'summary' => [
                'Tasks done' => count($all),
                'Average days' => $this->avg($all),
                'Median days' => $this->median($all),
            ],
        ];
    }

    /**
     * Open tasks past their due date today, by department (not limited by the date range).
     *
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function overdue(ReportFilters $f): array
    {
        $today = now()->toDateString();
        $tasks = $this->tasks($f)
            ->whereNotIn('tasks.status_id', $this->doneIds())
            ->get(['tasks.department_id', 'tasks.due_on']);

        $byDept = [];
        foreach ($tasks as $t) {
            $id = (int) $t->department_id;
            $byDept[$id] ??= ['open' => 0, 'overdue' => 0];
            $byDept[$id]['open']++;
            if ($t->due_on !== null && substr((string) $t->due_on, 0, 10) < $today) {
                $byDept[$id]['overdue']++;
            }
        }

        $names = $this->departmentNames();
        $rows = [];
        foreach ($byDept as $id => $c) {
            $rows[] = [
                'department' => $names[$id] ?? 'No department',
                'open' => $c['open'],
                'overdue' => $c['overdue'],
                // Each row exists because it has at least one open task.
                'overdue_pct' => round($c['overdue'] / $c['open'] * 100, 1),
            ];
        }
        usort($rows, fn (array $a, array $b) => $b['overdue'] <=> $a['overdue'] ?: strcmp((string) $a['department'], (string) $b['department']));

        $open = array_sum(array_column($rows, 'open'));
        $overdue = array_sum(array_column($rows, 'overdue'));

        return [
            'columns' => [
                $this->col('department', 'Department', 'text'),
                $this->col('open', 'Open tasks', 'number'),
                $this->col('overdue', 'Overdue', 'number'),
                $this->col('overdue_pct', 'Overdue %', 'percent'),
            ],
            'rows' => $rows,
            'summary' => ['Open tasks' => $open, 'Overdue' => $overdue],
        ];
    }

    /**
     * Open work per assignee today, plus hours they logged in the date range.
     *
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function workload(ReportFilters $f): array
    {
        $today = now()->toDateString();
        $weekEnd = now()->endOfWeek(CarbonImmutable::SUNDAY)->toDateString();

        $tasks = $this->tasks($f)
            ->whereNotIn('tasks.status_id', $this->doneIds())
            ->get(['tasks.assignee_id', 'tasks.due_on', 'tasks.priority']);

        $people = [];
        foreach ($tasks as $t) {
            $id = (int) $t->assignee_id;
            $people[$id] ??= ['open' => 0, 'overdue' => 0, 'due_week' => 0, 'urgent' => 0, 'minutes' => 0];
            $people[$id]['open']++;
            $due = $t->due_on !== null ? substr((string) $t->due_on, 0, 10) : null;
            if ($due !== null && $due < $today) {
                $people[$id]['overdue']++;
            } elseif ($due !== null && $due <= $weekEnd) {
                $people[$id]['due_week']++;
            }
            if (in_array($t->priority, ['high', 'urgent'], true)) {
                $people[$id]['urgent']++;
            }
        }

        $minutes = $this->entries($f)->groupBy('time_entries.user_id')
            ->selectRaw('time_entries.user_id as id, sum(time_entries.minutes) as minutes')
            ->pluck('minutes', 'id');
        foreach ($minutes as $id => $m) {
            $people[(int) $id] ??= ['open' => 0, 'overdue' => 0, 'due_week' => 0, 'urgent' => 0, 'minutes' => 0];
            $people[(int) $id]['minutes'] = (int) $m;
        }

        $names = DB::table('users')->whereIn('id', array_keys($people))->pluck('name', 'id');
        $rows = [];
        foreach ($people as $id => $p) {
            $rows[] = [
                'person' => $id === 0 ? 'Unassigned' : (string) ($names[$id] ?? 'Former member'),
                'open' => $p['open'],
                'overdue' => $p['overdue'],
                'due_week' => $p['due_week'],
                'urgent' => $p['urgent'],
                'hours' => round($p['minutes'] / 60, 2),
            ];
        }
        usort($rows, fn (array $a, array $b) => $b['open'] <=> $a['open'] ?: strcmp((string) $a['person'], (string) $b['person']));

        return [
            'columns' => [
                $this->col('person', 'Person', 'text'),
                $this->col('open', 'Open tasks', 'number'),
                $this->col('overdue', 'Overdue', 'number'),
                $this->col('due_week', 'Due this week', 'number'),
                $this->col('urgent', 'High / urgent', 'number'),
                $this->col('hours', 'Hours logged (range)', 'hours'),
            ],
            'rows' => $rows,
            'summary' => [
                'Open tasks' => array_sum(array_column($rows, 'open')),
                'Hours logged' => round(array_sum(array_column($rows, 'hours')), 2),
            ],
        ];
    }

    /**
     * Handoffs created in the range: how long the receiving department waited
     * for the source task to be finished, by receiving department.
     *
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function handoffs(ReportFilters $f): array
    {
        $rows = $this->tasks($f)
            ->join('tasks as src', 'src.id', '=', 'tasks.handoff_from_task_id')
            ->whereBetween('tasks.created_at', [$f->from, $f->to])
            ->get(['tasks.department_id', 'tasks.created_at', 'src.completed_at as source_done_at']);

        $now = now()->toDateTimeString();
        $byDept = [];
        foreach ($rows as $r) {
            $id = (int) $r->department_id;
            $byDept[$id] ??= ['waits' => [], 'waiting' => 0];
            $byDept[$id]['waits'][] = $this->days((string) $r->created_at, $r->source_done_at !== null ? (string) $r->source_done_at : $now);
            if ($r->source_done_at === null) {
                $byDept[$id]['waiting']++;
            }
        }

        $names = $this->departmentNames();
        $out = [];
        $all = [];
        foreach ($byDept as $id => $d) {
            $all = [...$all, ...$d['waits']];
            $out[] = [
                'department' => $names[$id] ?? 'No department',
                'handoffs' => count($d['waits']),
                'still_waiting' => $d['waiting'],
                'avg_wait_days' => $this->avg($d['waits']),
                'max_wait_days' => round(max([0.0, ...$d['waits']]), 1),
            ];
        }
        usort($out, fn (array $a, array $b) => strcmp((string) $a['department'], (string) $b['department']));

        return [
            'columns' => [
                $this->col('department', 'Receiving department', 'text'),
                $this->col('handoffs', 'Handoffs', 'number'),
                $this->col('still_waiting', 'Still waiting', 'number'),
                $this->col('avg_wait_days', 'Average wait (days)', 'days'),
                $this->col('max_wait_days', 'Longest wait (days)', 'days'),
            ],
            'rows' => $out,
            'summary' => ['Handoffs' => count($all), 'Average wait (days)' => $this->avg($all)],
        ];
    }

    /**
     * Logged time in the range grouped by workspace, project, task, person or department.
     *
     * @return array{columns: list<array{key: string, label: string, type: string}>, rows: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function time(ReportFilters $f, string $group): array
    {
        $column = match ($group) {
            'workspace' => 'time_entries.workspace_id',
            'project' => 'time_entries.project_id',
            'task' => 'time_entries.task_id',
            'department' => 'time_entries.department_id',
            default => 'time_entries.user_id',
        };
        $group = array_key_exists($group, self::TIME_GROUPS) ? $group : 'person';

        $sums = $this->entries($f)
            ->groupBy($column)
            ->selectRaw("{$column} as id")
            ->selectRaw('sum(time_entries.minutes) as total')
            ->selectRaw('sum(case when time_entries.is_billable then time_entries.minutes else 0 end) as billable')
            ->selectRaw('sum(case when time_entries.approved_at is not null then time_entries.minutes else 0 end) as approved')
            ->get();

        $ids = $sums->pluck('id')->filter()->map(fn ($id) => (int) $id)->all();
        $names = match ($group) {
            'workspace' => DB::table('workspaces')->whereIn('id', $ids)->pluck('name', 'id'),
            'project' => DB::table('projects')->whereIn('id', $ids)->pluck('name', 'id'),
            'task' => DB::table('tasks')->whereIn('id', $ids)->pluck('title', 'id'),
            'department' => DB::table('departments')->whereIn('id', $ids)->pluck('name', 'id'),
            default => DB::table('users')->whereIn('id', $ids)->pluck('name', 'id'),
        };
        $none = ['project' => 'No project', 'task' => 'No task', 'department' => 'No department'][$group] ?? 'Unknown';

        $rows = [];
        foreach ($sums as $s) {
            $total = (int) $s->total;
            $billable = (int) $s->billable;
            $rows[] = [
                'name' => $s->id === null ? $none : (string) ($names[(int) $s->id] ?? $none),
                'hours' => round($total / 60, 2),
                'billable_hours' => round($billable / 60, 2),
                'non_billable_hours' => round(($total - $billable) / 60, 2),
                'approved_hours' => round((int) $s->approved / 60, 2),
                'minutes' => $total,
            ];
        }
        usort($rows, fn (array $a, array $b) => $b['minutes'] <=> $a['minutes'] ?: strcmp((string) $a['name'], (string) $b['name']));

        $minutes = array_sum(array_column($rows, 'minutes'));
        $billable = (int) $sums->sum(fn ($s) => (int) $s->billable);

        return [
            'columns' => [
                $this->col('name', self::TIME_GROUPS[$group], 'text'),
                $this->col('hours', 'Hours', 'hours'),
                $this->col('billable_hours', 'Billable', 'hours'),
                $this->col('non_billable_hours', 'Non-billable', 'hours'),
                $this->col('approved_hours', 'Approved', 'hours'),
                $this->col('minutes', 'Minutes', 'number'),
            ],
            'rows' => $rows,
            'summary' => [
                'Hours' => round($minutes / 60, 2),
                'Billable hours' => round($billable / 60, 2),
                'Non-billable hours' => round(($minutes - $billable) / 60, 2),
            ],
        ];
    }

    /**
     * Live tasks in workspaces the filters allow.
     */
    private function tasks(ReportFilters $f): Builder
    {
        return DB::table('tasks')
            ->join('workspaces', 'workspaces.id', '=', 'tasks.workspace_id')
            ->whereNull('tasks.deleted_at')
            ->whereNull('workspaces.deleted_at')
            ->when($f->allowedWorkspaceIds !== null, fn (Builder $q) => $q->whereIn('tasks.workspace_id', $f->allowedWorkspaceIds ?? []))
            ->when($f->workspaceId !== null, fn (Builder $q) => $q->where('tasks.workspace_id', $f->workspaceId))
            ->when($f->departmentId !== null, fn (Builder $q) => $q->where('tasks.department_id', $f->departmentId));
    }

    /**
     * Finished time entries (no running timers) dated inside the range.
     */
    private function entries(ReportFilters $f): Builder
    {
        return DB::table('time_entries')
            ->join('workspaces', 'workspaces.id', '=', 'time_entries.workspace_id')
            ->whereNull('time_entries.deleted_at')
            ->whereNull('workspaces.deleted_at')
            ->where(fn (Builder $q) => $q->whereNull('time_entries.started_at')->orWhereNotNull('time_entries.ended_at'))
            // whereDate: SQLite stores date casts as 'Y-m-d 00:00:00'.
            ->whereDate('time_entries.entry_date', '>=', $f->from->toDateString())
            ->whereDate('time_entries.entry_date', '<=', $f->to->toDateString())
            ->when($f->allowedWorkspaceIds !== null, fn (Builder $q) => $q->whereIn('time_entries.workspace_id', $f->allowedWorkspaceIds ?? []))
            ->when($f->workspaceId !== null, fn (Builder $q) => $q->where('time_entries.workspace_id', $f->workspaceId))
            ->when($f->departmentId !== null, fn (Builder $q) => $q->where('time_entries.department_id', $f->departmentId));
    }

    /**
     * @return list<int>
     */
    private function doneIds(): array
    {
        return TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * @return array<int, string>
     */
    private function departmentNames(): array
    {
        return DB::table('departments')->pluck('name', 'id')->map(fn ($n) => (string) $n)->all();
    }

    private function days(string $from, string $to): float
    {
        // Carbon 3 diffs are signed; clamp so clock skew (e.g. a handoff created just after its source finished) never goes negative.
        return max(0.0, CarbonImmutable::parse($from)->diffInSeconds(CarbonImmutable::parse($to)) / 86400);
    }

    /**
     * @param  list<float>  $values
     */
    private function avg(array $values): float
    {
        return $values === [] ? 0.0 : round(array_sum($values) / count($values), 1);
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return round(count($values) % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2, 1);
    }

    /**
     * @return array{key: string, label: string, type: string}
     */
    private function col(string $key, string $label, string $type): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }
}
