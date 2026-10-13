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

    public function destroy(Holiday $holiday, ActivityLogger $activity): RedirectResponse
    {
        $holiday->delete();
        $activity->log('holiday.removed', null, ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);

        return back();
    }
}
