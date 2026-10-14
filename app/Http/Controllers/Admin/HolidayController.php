<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\Holiday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Holiday calendar (B-14). Seeded with the Philippine 2026 list (Proclamation No. 1006);
 * admins add proclaimed extras (e.g. Eid'l Fitr) or local holidays.
 */
class HolidayController extends Controller
{
    public function index(Request $request): Response
    {
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'between:2020,2100']])['year'] ?? now()->year);

        return Inertia::render('admin/Holidays', [
            'year' => $year,
            'holidays' => Holiday::query()->whereYear('date', $year)->orderBy('date')->get()
                ->map(fn (Holiday $h) => ['id' => $h->id, 'date' => $h->date->toDateString(), 'name' => $h->name, 'kind' => $h->kind])->values(),
            'kinds' => Holiday::KINDS,
            // E-95: preview / result of the last pasted list.
            'importResult' => $request->session()->get('holidayImport'),
        ]);
    }

    public function store(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'name' => ['required', 'string', 'max:120'],
            'kind' => ['required', Rule::in(array_keys(Holiday::KINDS))],
        ]);
        if (Holiday::query()->whereDate('date', $data['date'])->exists()) {
            throw ValidationException::withMessages(['date' => __('There is already a holiday on that date.')]);
        }
        $holiday = Holiday::create($data);
        $activity->log('holiday.added', $holiday, $data);

        return back();
    }

    /**
     * E-95: paste next year's proclamation, one holiday per line:
     *   2027-01-01, New Year's Day, regular
     *   2027-02-25, EDSA People Power Revolution Anniversary, special working
     * The kind is optional (regular by default). Dates already in the calendar are skipped.
     * "preview" checks without saving.
     */
    public function import(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:20000'],
            'preview' => ['boolean'],
        ]);
        $rows = [];
        $seen = [];
        foreach (preg_split('/\R/', (string) $data['text']) ?: [] as $n => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $parts = array_map('trim', str_getcsv(str_replace("\t", ',', $line)));
            [$date, $name, $kind] = [$parts[0], $parts[1] ?? '', self::kind($parts[2] ?? 'regular')];
            $row = ['line' => $n + 1, 'date' => $date, 'name' => $name, 'kind' => $kind, 'status' => 'new', 'error' => null];
            $valid = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4));
            if (! $valid) {
                $row = [...$row, 'status' => 'error', 'error' => __('Date must look like 2027-01-01.')];
            } elseif ($name === '' || mb_strlen($name) > 120) {
                $row = [...$row, 'status' => 'error', 'error' => __('Add the holiday name (up to 120 characters).')];
            } elseif ($kind === null) {
                $row = [...$row, 'status' => 'error', 'error' => __('Kind must be regular, special or special working.')];
            } elseif (isset($seen[$date]) || Holiday::query()->whereDate('date', $date)->exists()) {
                $row = [...$row, 'status' => 'duplicate'];
            }
            $seen[$date] = true;
            $rows[] = $row;
        }

        $created = 0;
        if (! $request->boolean('preview')) {
            foreach ($rows as $row) {
                if ($row['status'] === 'new') {
                    Holiday::create(['date' => $row['date'], 'name' => $row['name'], 'kind' => $row['kind']]);
                    $created++;
                }
            }
            $activity->log('holiday.imported', null, ['created' => $created, 'skipped' => count($rows) - $created]);
            Inertia::flash('toast', ['type' => 'success', 'message' => trans_choice('{0} No new holidays added.|{1} 1 holiday added.|[2,*] :count holidays added.', $created, ['count' => $created])]);
        }

        return back()->with('holidayImport', ['preview' => $request->boolean('preview'), 'created' => $created, 'rows' => $rows]);
    }

    /** "special working" / "Special Non-Working Day" / "regular holiday" -> a kind key. */
    private static function kind(string $raw): ?string
    {
        $k = strtolower(trim($raw));

        return match (true) {
            $k === '' || str_starts_with($k, 'regular') => 'regular',
            str_contains($k, 'working') && ! str_contains($k, 'non') => 'special_working',
            str_starts_with($k, 'special') => 'special',
            default => null,
        };
    }

    public function destroy(Holiday $holiday, ActivityLogger $activity): RedirectResponse
    {
        $holiday->delete();
        $activity->log('holiday.removed', null, ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);

        return back();
    }
}
