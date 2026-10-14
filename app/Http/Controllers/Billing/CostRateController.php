<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\CostRates;
use App\Domain\Billing\Money;
use App\Domain\Identity\Permissions;
use App\Http\Controllers\Controller;
use App\Models\CostRate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * E-77: internal cost per person (PHP per hour, effective-dated). Finance sees it
 * (billing.view); billing.settings changes it. Kept apart from the bill rates.
 */
class CostRateController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $viewer */
        $viewer = $request->user();
        $today = now()->toDateString();
        $rates = CostRate::query()->orderByDesc('effective_from')->get()->groupBy('user_id');

        return Inertia::render('billing/CostRates', [
            'people' => User::query()->with('primaryDepartment:id,name')
                ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $rates->keys()->all()))
                ->orderBy('name')->get(['id', 'name', 'title', 'primary_department_id', 'is_active'])
                ->map(function (User $u) use ($rates, $today): array {
                    $history = $rates->get($u->id, collect());
                    $current = $history->first(fn (CostRate $r) => $r->effective_from->toDateString() <= $today);

                    return [
                        'id' => $u->id,
                        'name' => $u->name,
                        'title' => $u->title,
                        'department' => $u->primaryDepartment?->name,
                        'active' => $u->is_active,
                        'current_minor' => $current?->cost_minor,
                        'history' => $history->map(fn (CostRate $r): array => [
                            'id' => $r->id, 'cost_minor' => $r->cost_minor, 'from' => $r->effective_from->toDateString(), 'note' => $r->note,
                        ])->values(),
                    ];
                })->values(),
            'canEdit' => $viewer->can(Permissions::BILLING_SETTINGS),
            'today' => $today,
        ]);
    }

    public function store(Request $request, CostRates $costs): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')],
            'cost' => ['required', 'regex:'.Money::INPUT_REGEX],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        /** @var User $by */
        $by = $request->user();
        $person = User::query()->findOrFail((int) $data['user_id']);
        $costs->set($person, Money::parse((string) $data['cost']), (string) $data['effective_from'], $data['note'] ?? null, $by);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cost rate for :n saved.', ['n' => $person->name])]);

        return back();
    }
}
