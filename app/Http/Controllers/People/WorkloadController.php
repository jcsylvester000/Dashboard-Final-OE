<?php

namespace App\Http\Controllers\People;

use App\Domain\Identity\Permissions;
use App\Domain\People\Capacity;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Workload (B-05): each person's planned hours (task estimates spread over their
 * working days from start to due) and logged hours per week, against capacity
 * (8h weekdays minus holidays and approved leave). Leads see their departments.
 */
class WorkloadController extends Controller
{
    public function __invoke(Request $request, Capacity $capacity): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $q = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'weeks' => ['nullable', 'integer', 'in:2,4,6,8'],
            'department' => ['nullable', 'integer'],
        ]);

        $all = $viewer->can(Permissions::REPORTS_VIEW_ALL);
        $led = Department::query()->where('lead_user_id', $viewer->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        abort_unless($all || $led !== [], 403);

        $weeks = (int) ($q['weeks'] ?? 4);
        $start = CarbonImmutable::parse($q['from'] ?? now()->toDateString())->startOfWeek(CarbonImmutable::MONDAY);
        $end = $start->addWeeks($weeks)->subDay();
        $today = now()->toDateString();

        $people = User::query()->active()->with('primaryDepartment:id,name,color')
            ->when(! $all, fn (Builder $b) => $b->whereIn('primary_department_id', $led))
            ->when($q['department'] ?? null, fn (Builder $b, $id) => $b->where('primary_department_id', (int) $id))
            ->whereNotNull('primary_department_id')
            ->orderBy('name')->get(['id', 'name', 'title', 'primary_department_id']);
        $ids = $people->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Capacity needs the whole span (overdue work lands on this week).
        $capacity->load($start->toDateString(), $end->toDateString(), $ids);
        $weekStarts = [];
        for ($w = 0; $w < $weeks; $w++) {
            $weekStarts[] = $start->addWeeks($w)->toDateString();
        }
        $weekOf = fn (string $day): string => CarbonImmutable::parse($day)->startOfWeek(CarbonImmutable::MONDAY)->toDateString();

        /** @var array<int, array<string, int>> $planned */
        $planned = [];
        /** @var array<int, array<string, array<string, array{key: string, title: string, minutes: int}>>> $detail */
        $detail = [];
        $tasks = Task::query()->with('workspace:id,key')
            ->whereIn('assignee_id', $ids)->whereNull('completed_at')
            ->whereNotNull('estimate_minutes')->where('estimate_minutes', '>', 0)->whereNotNull('due_on')
            ->whereDate('due_on', '>=', $start->subWeeks(52)->toDateString())
            ->where(fn (Builder $b) => $b->whereNull('start_on')->orWhereDate('start_on', '<=', $end->toDateString()))
            ->get(['id', 'number', 'title', 'workspace_id', 'assignee_id', 'start_on', 'due_on', 'estimate_minutes']);
        foreach ($tasks as $t) {
            $due = $t->due_on?->toDateString() ?? $today;
            $from = $t->start_on?->toDateString() ?? $due;
            // Planned = work still to do, from today on. Late work lands on today.
            if ($due < $today) {
                $due = $today;
            }
            $from = max($from, $today);
            $days = $capacity->workingDays($from, $due) ?: [$due];
            $per = intdiv((int) $t->estimate_minutes, count($days));
            $rest = (int) $t->estimate_minutes - $per * count($days);
            foreach ($days as $i => $d) {
                $wk = $weekOf($d);
                if (! in_array($wk, $weekStarts, true)) {
                    continue;
                }
                $m = $per + ($i === 0 ? $rest : 0);
                $planned[(int) $t->assignee_id][$wk] = ($planned[(int) $t->assignee_id][$wk] ?? 0) + $m;
                // One line per task per week (not per day).
                $k = $t->keyLabel();
                $detail[(int) $t->assignee_id][$wk][$k] ??= ['key' => $k, 'title' => $t->title, 'minutes' => 0];
                $detail[(int) $t->assignee_id][$wk][$k]['minutes'] += $m;
            }
        }

        $logged = TimeEntry::query()->whereIn('user_id', $ids)
            ->whereDate('entry_date', '>=', $start->toDateString())->whereDate('entry_date', '<=', $end->toDateString())
            ->get(['user_id', 'entry_date', 'minutes'])
            ->groupBy(fn (TimeEntry $e) => $e->user_id.'|'.$weekOf($e->entry_date->toDateString()))
            ->map(fn ($g) => (int) $g->sum('minutes'));

        return Inertia::render('people/Workload', [
            'range' => ['from' => $start->toDateString(), 'to' => $end->toDateString(), 'weeks' => $weeks, 'today' => $today],
            'weekStarts' => $weekStarts,
            'holidays' => Capacity::holidays($start->toDateString(), $end->toDateString()),
            'people' => $people->map(function (User $u) use ($weekStarts, $capacity, $planned, $logged, $detail, $today) {
                $cells = [];
                foreach ($weekStarts as $wk) {
                    $wkEnd = CarbonImmutable::parse($wk)->addDays(6)->toDateString();
                    // Planned work starts today, so this week compares against the days that are left.
                    $current = $wk <= $today && $today <= $wkEnd;
                    $cap = $capacity->minutes($u->id, $current ? $today : $wk, $wkEnd);
                    $plan = $planned[$u->id][$wk] ?? 0;
                    $cells[] = [
                        'week' => $wk,
                        'capacity' => $cap,
                        'planned' => $plan,
                        'logged' => (int) ($logged[$u->id.'|'.$wk] ?? 0),
                        'leave_days' => $capacity->leaveDays($u->id, $wk, $wkEnd),
                        'load' => $cap > 0 ? (int) round($plan / $cap * 100) : ($plan > 0 ? 999 : 0),
                        'tasks' => array_slice(array_values($detail[$u->id][$wk] ?? []), 0, 8),
                        'remaining_only' => $current,
                    ];
                }

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'title' => $u->title,
                    'department' => $u->primaryDepartment?->only(['id', 'name', 'color']),
                    'weeks' => $cells,
                ];
            })->values(),
            'departments' => Department::query()->when(! $all, fn (Builder $b) => $b->whereIn('id', $led))->orderBy('position')->get(['id', 'name']),
            'filters' => array_filter(['department' => $q['department'] ?? null]),
        ]);
    }
}
