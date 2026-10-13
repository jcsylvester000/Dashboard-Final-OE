<?php

namespace App\Domain\People;

use App\Models\Holiday;
use App\Models\LeaveRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * How many minutes a person can work in a date range (B-05, B-14, B-16):
 * Monday-Friday x 8h, minus regular/special holidays, minus approved leave.
 * Loads holidays and leave once per range, so it is cheap for a whole team.
 */
class Capacity
{
    public const DAY_MINUTES = 480;

    /** @var array<string, true> */
    private array $daysOff = [];

    /** @var array<int, array<string, true>> user_id => leave days */
    private array $leave = [];

    private string $from = '';

    private string $to = '';

    /**
     * @param  list<int>  $userIds
     */
    public function load(string $from, string $to, array $userIds = []): self
    {
        $this->from = $from;
        $this->to = $to;
        $this->daysOff = [];
        foreach (Holiday::query()->whereIn('kind', Holiday::DAYS_OFF)->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get(['date']) as $h) {
            $this->daysOff[$h->date->toDateString()] = true;
        }

        $this->leave = [];
        if ($userIds !== []) {
            $requests = LeaveRequest::query()->where('status', LeaveRequest::APPROVED)->whereIn('user_id', $userIds)
                ->whereDate('ends_on', '>=', $from)->whereDate('starts_on', '<=', $to)
                ->get(['user_id', 'starts_on', 'ends_on']);
            foreach ($requests as $r) {
                foreach ($this->days(max($r->starts_on->toDateString(), $from), min($r->ends_on->toDateString(), $to)) as $d) {
                    $this->leave[$r->user_id][$d] = true;
                }
            }
        }

        return $this;
    }

    /**
     * Working days (weekdays that are not holidays) in [from, to], ignoring leave.
     *
     * @return list<string>
     */
    public function workingDays(string $from, string $to): array
    {
        return array_values(array_filter($this->days($from, $to), fn (string $d) => ! isset($this->daysOff[$d])));
    }

    public function isDayOff(string $date, ?int $userId = null): bool
    {
        $day = CarbonImmutable::parse($date);

        return $day->isWeekend() || isset($this->daysOff[$date]) || ($userId !== null && isset($this->leave[$userId][$date]));
    }

    /** Minutes the person is available in [from, to] (must be inside the loaded range). */
    public function minutes(int $userId, ?string $from = null, ?string $to = null): int
    {
        $days = $this->workingDays($from ?? $this->from, $to ?? $this->to);
        $onLeave = count(array_filter($days, fn (string $d) => isset($this->leave[$userId][$d])));

        return (count($days) - $onLeave) * self::DAY_MINUTES;
    }

    /** Approved leave days for the person in [from, to]. */
    public function leaveDays(int $userId, ?string $from = null, ?string $to = null): int
    {
        return count(array_filter($this->workingDays($from ?? $this->from, $to ?? $this->to), fn (string $d) => isset($this->leave[$userId][$d])));
    }

    /**
     * Holidays (any kind) in the range, for display.
     *
     * @return Collection<int, array{date: string, name: string, kind: string}>
     */
    public static function holidays(string $from, string $to): Collection
    {
        return Holiday::query()->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->orderBy('date')->get()
            ->map(fn (Holiday $h) => ['date' => $h->date->toDateString(), 'name' => $h->name, 'kind' => $h->kind])->values();
    }

    /**
     * Weekdays in [from, to] (inclusive).
     *
     * @return list<string>
     */
    private function days(string $from, string $to): array
    {
        $out = [];
        for ($d = CarbonImmutable::parse($from); $d->toDateString() <= $to; $d = $d->addDay()) {
            if (! $d->isWeekend()) {
                $out[] = $d->toDateString();
            }
        }

        return $out;
    }
}
