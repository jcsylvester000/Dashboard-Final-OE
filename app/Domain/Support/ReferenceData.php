<?php

namespace App\Domain\Support;

use App\Models\Department;
use App\Models\Holiday;
use Illuminate\Support\Facades\Cache;

/**
 * E-119: small, rarely changing lists every page needs, cached in Redis as plain arrays
 * (Redis here refuses to unserialize objects). Saving or deleting a department or a holiday
 * clears its key (model events), so pages never show stale data after an edit.
 */
class ReferenceData
{
    public const DEPARTMENTS_KEY = 'ref:departments:v1';

    private const TTL_SECONDS = 86400;

    /**
     * Active departments in board order.
     *
     * @return list<array{id: int, name: string, color: string}>
     */
    public static function departments(): array
    {
        /** @var list<array{id: int, name: string, color: string}> $rows */
        $rows = Cache::remember(self::DEPARTMENTS_KEY, self::TTL_SECONDS, fn (): array => Department::query()->active()->orderBy('position')
            ->get(['id', 'name', 'color'])
            ->map(fn (Department $d): array => ['id' => (int) $d->id, 'name' => (string) $d->name, 'color' => (string) $d->color])
            ->values()->all());

        return $rows;
    }

    /**
     * Holiday dates that are days off, for one calendar year ("Y-m-d" => true).
     *
     * @return array<string, true>
     */
    public static function daysOff(int $year): array
    {
        /** @var array<string, true> $days */
        $days = Cache::remember(self::holidayKey($year), self::TTL_SECONDS, function () use ($year): array {
            $out = [];
            foreach (Holiday::query()->whereIn('kind', Holiday::DAYS_OFF)->whereYear('date', $year)->get(['date']) as $h) {
                $out[$h->date->toDateString()] = true;
            }

            return $out;
        });

        return $days;
    }

    public static function holidayKey(int $year): string
    {
        return 'ref:holidays:v1:'.$year;
    }

    public static function forgetDepartments(): void
    {
        Cache::forget(self::DEPARTMENTS_KEY);
    }

    public static function forgetHolidays(int ...$years): void
    {
        foreach ($years as $year) {
            Cache::forget(self::holidayKey($year));
        }
    }
}
