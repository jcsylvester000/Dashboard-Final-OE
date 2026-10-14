<?php

namespace App\Domain\People;

use App\Domain\Billing\CostRates;
use App\Domain\Billing\Money;
use App\Domain\Billing\RateResolver;
use App\Models\TimeEntry;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * People report (B-16): per person per month - capacity, hours logged, billable %,
 * utilisation (billable hours / capacity), internal cost and value at client rates, margin.
 * Value is what the time is worth at today's rate cards, not what was invoiced.
 * E-77: cost comes from the Finance cost table when the person has a rate there.
 */
class PeopleReport
{
    public function __construct(
        private readonly Capacity $capacity,
        private readonly RateResolver $rates,
        private readonly CostRates $costs,
    ) {}

    /**
     * @return array{from: string, to: string, rows: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function month(string $month, ?int $departmentId = null): array
    {
        $first = CarbonImmutable::parse($month.'-01');
        [$from, $to] = [$first->toDateString(), $first->endOfMonth()->toDateString()];

        $people = User::query()->with('primaryDepartment:id,name')
            ->where(fn ($q) => $q->where('is_active', true)->orWhereHas('timeEntries', fn ($t) => $t->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to)))
            ->whereNotNull('primary_department_id')
            ->when($departmentId, fn ($q, $id) => $q->where('primary_department_id', $id))
            ->orderBy('name')->get(['id', 'name', 'title', 'primary_department_id', 'is_active']);
        $ids = $people->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->capacity->load($from, $to, $ids);

        $entries = TimeEntry::query()->whereIn('user_id', $ids)
            ->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to)
            ->where(fn ($q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'))
            ->get(['user_id', 'workspace_id', 'department_id', 'entry_date', 'minutes', 'is_billable'])
            ->groupBy('user_id');

        $rows = [];
        $totals = ['capacity' => 0, 'logged' => 0, 'billable' => 0, 'cost_minor' => 0, 'value_minor' => 0];
        foreach ($people as $u) {
            $logged = $billable = $cost = $value = 0;
            $missingRate = false;
            foreach ($entries->get($u->id, collect()) as $e) {
                $card = $this->rates->resolve((int) $e->workspace_id, $e->department_id, $u->id, $e->entry_date->toDateString());
                $logged += $e->minutes;
                $personal = $this->costs->perHour($u->id, $e->entry_date->toDateString());
                if ($personal !== null) {
                    $cost += Money::mulDiv($e->minutes, $personal, 60);
                } elseif ($card === null || $card->cost_minor === null) {
                    $missingRate = true;
                } else {
                    $cost += Money::mulDiv($e->minutes, $card->cost_minor, 60);
                }
                if ($e->is_billable) {
                    $billable += $e->minutes;
                    if ($card !== null) {
                        $value += Money::mulDiv($e->minutes, $card->rate_minor, 60);
                    }
                }
            }
            $cap = $this->capacity->minutes($u->id);
            $rows[] = [
                'id' => $u->id,
                'name' => $u->name,
                'title' => $u->title,
                'department' => $u->primaryDepartment?->name,
                'active' => $u->is_active,
                'capacity' => $cap,
                'leave_days' => $this->capacity->leaveDays($u->id),
                'logged' => $logged,
                'billable' => $billable,
                'billable_pct' => $logged > 0 ? (int) round($billable / $logged * 100) : 0,
                'utilisation_pct' => $cap > 0 ? (int) round($billable / $cap * 100) : 0,
                'logged_pct' => $cap > 0 ? (int) round($logged / $cap * 100) : 0,
                'cost_minor' => $cost,
                'value_minor' => $value,
                'margin_minor' => $value - $cost,
                'missing_cost' => $missingRate,
            ];
            foreach (['capacity' => $cap, 'logged' => $logged, 'billable' => $billable, 'cost_minor' => $cost, 'value_minor' => $value] as $k => $v) {
                $totals[$k] += $v;
            }
        }

        return ['from' => $from, 'to' => $to, 'rows' => $rows, 'totals' => $totals];
    }
}
