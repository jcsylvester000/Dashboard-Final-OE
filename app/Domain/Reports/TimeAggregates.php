<?php

namespace App\Domain\Reports;

use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * E-129: time_daily holds finished, not-deleted time per day, person, client, project, task
 * and department. Changes refresh only the (person, day) slices they touch; the nightly
 * reports:aggregate check compares every day with the raw entries and repairs any drift.
 */
class TimeAggregates
{
    private const COLUMNS = ['entry_date', 'user_id', 'workspace_id', 'project_id', 'task_id', 'department_id', 'minutes', 'billable_minutes', 'approved_minutes', 'entries'];

    /**
     * Rebuild the slices for these (user id, Y-m-d) pairs.
     *
     * @param  iterable<array{0: int|null, 1: string|null}>  $slices
     */
    public function refresh(iterable $slices): void
    {
        $seen = [];
        foreach ($slices as [$userId, $date]) {
            if ($userId === null || $date === null || $date === '') {
                continue;
            }
            $day = substr($date, 0, 10);
            $key = $userId.'|'.$day;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            DB::transaction(function () use ($userId, $day) {
                DB::table('time_daily')->where('user_id', $userId)->whereDate('entry_date', $day)->delete();
                DB::table('time_daily')->insertUsing(self::COLUMNS,
                    $this->grouped(fn (Builder $q) => $q->where('user_id', $userId)->whereDate('entry_date', $day)));
            });
        }
    }

    /**
     * Refresh the slices behind these entry ids (after bulk updates that skip model events).
     *
     * @param  list<int>  $ids
     */
    public function refreshEntries(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->refresh(TimeEntry::query()->withTrashed()->whereIn('id', $ids)->get(['user_id', 'entry_date'])
            ->map(fn (TimeEntry $e): array => [$e->user_id, $e->entry_date->toDateString()])->all());
    }

    /**
     * Rebuild a date range (or everything).
     */
    public function rebuild(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): int
    {
        return DB::transaction(function () use ($from, $to) {
            $range = function ($q) use ($from, $to) {
                if ($from !== null) {
                    $q->whereDate('entry_date', '>=', $from->toDateString());
                }
                if ($to !== null) {
                    $q->whereDate('entry_date', '<=', $to->toDateString());
                }
            };
            $query = DB::table('time_daily');
            $range($query);
            $query->delete();

            return DB::table('time_daily')->insertUsing(self::COLUMNS, $this->grouped($range));
        });
    }

    /**
     * (person, day) slices whose aggregate differs from the raw entries, at full detail.
     *
     * @return list<array{0: int, 1: string}>
     */
    public function drift(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dims = ['user_id', 'entry_date', 'workspace_id', 'project_id', 'task_id', 'department_id'];
        $within = fn (Builder $q) => $q->whereDate('entry_date', '>=', $from->toDateString())->whereDate('entry_date', '<=', $to->toDateString());
        $index = function ($rows) {
            $out = [];
            foreach ($rows as $r) {
                $key = implode('|', [$r->user_id, substr((string) $r->entry_date, 0, 10), $r->workspace_id, $r->project_id ?? '', $r->task_id ?? '', $r->department_id ?? '']);
                $out[$key] = implode('|', [(int) $r->m, (int) $r->b, (int) $r->a, (int) $r->n]);
            }

            return $out;
        };

        $raw = $index($within($this->finished())->groupBy($dims)
            ->selectRaw(implode(', ', $dims).', sum(minutes) as m, sum('.self::billable().') as b, sum('.self::approved().') as a, count(*) as n')->get());
        $agg = $index($within(DB::table('time_daily'))->groupBy($dims)
            ->selectRaw(implode(', ', $dims).', sum(minutes) as m, sum(billable_minutes) as b, sum(approved_minutes) as a, sum(entries) as n')->get());

        $slices = [];
        foreach (array_unique([...array_keys($raw), ...array_keys($agg)]) as $key) {
            if (($raw[$key] ?? null) !== ($agg[$key] ?? null)) {
                [$user, $day] = explode('|', $key);
                $slices[$user.'|'.$day] = [(int) $user, $day];
            }
        }

        return array_values($slices);
    }

    /**
     * The grouped SELECT that fills time_daily.
     */
    private function grouped(callable $scope): Builder
    {
        $q = $this->finished();
        $scope($q);

        return $q->groupBy('entry_date', 'user_id', 'workspace_id', 'project_id', 'task_id', 'department_id')
            ->selectRaw('entry_date, user_id, workspace_id, project_id, task_id, department_id, sum(minutes), sum('.self::billable().'), sum('.self::approved().'), count(*)');
    }

    private function finished(): Builder
    {
        return DB::table('time_entries')
            ->whereNull('deleted_at')
            ->where(fn (Builder $q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'));
    }

    private static function billable(): string
    {
        return 'case when is_billable then minutes else 0 end';
    }

    private static function approved(): string
    {
        return 'case when approved_at is not null then minutes else 0 end';
    }
}
