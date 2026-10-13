<?php

namespace Database\Seeders;

use App\Models\Holiday;
use Illuminate\Database\Seeder;

/**
 * Philippine holidays for 2026 per Proclamation No. 1006 (signed 2025-09-03).
 * Eid'l Fitr and Eid'l Adha are proclaimed separately: add them in Admin > Holidays
 * once announced. Safe to re-run (keyed by date).
 */
class HolidaySeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: string}> */
    public const PH_2026 = [
        ['2026-01-01', "New Year's Day", 'regular'],
        ['2026-02-17', 'Chinese New Year', 'special'],
        ['2026-02-25', 'EDSA People Power Revolution Anniversary', 'special_working'],
        ['2026-04-02', 'Maundy Thursday', 'regular'],
        ['2026-04-03', 'Good Friday', 'regular'],
        ['2026-04-04', 'Black Saturday', 'special'],
        ['2026-04-09', 'Araw ng Kagitingan', 'regular'],
        ['2026-05-01', 'Labor Day', 'regular'],
        ['2026-06-12', 'Independence Day', 'regular'],
        ['2026-08-21', 'Ninoy Aquino Day', 'special'],
        ['2026-08-31', 'National Heroes Day', 'regular'],
        ['2026-11-01', "All Saints' Day", 'special'],
        ['2026-11-02', "All Souls' Day", 'special'],
        ['2026-11-30', 'Bonifacio Day', 'regular'],
        ['2026-12-08', 'Feast of the Immaculate Conception of Mary', 'special'],
        ['2026-12-24', 'Christmas Eve', 'special'],
        ['2026-12-25', 'Christmas Day', 'regular'],
        ['2026-12-30', 'Rizal Day', 'regular'],
        ['2026-12-31', 'Last Day of the Year', 'special'],
    ];

    public function run(): void
    {
        foreach (self::PH_2026 as [$date, $name, $kind]) {
            // whereDate: SQLite keeps a time part on date columns (W22).
            $holiday = Holiday::query()->whereDate('date', $date)->first() ?? new Holiday(['date' => $date]);
            $holiday->fill(['name' => $name, 'kind' => $kind])->save();
        }
    }
}
