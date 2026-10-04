<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\Money;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\RateCard;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Agency-wide hourly rates (and internal cost rates). Client-specific overrides
 * are added from the client's billing page with the same store endpoint.
 * Rates are effective-dated: add a new card rather than editing an old one.
 */
class RateCardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('billing/Rates', [
            'rates' => RateCard::query()->with(['department:id,name', 'user:id,name'])
                ->whereNull('workspace_id')->latest('effective_from')->get()
                ->map(fn (RateCard $r): array => ClientBillingController::rateRow($r))->values(),
            'departments' => Department::query()->orderBy('position')->get(['id', 'name']),
            'people' => User::query()->active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'workspace_id' => ['nullable', 'integer', Rule::exists('workspaces', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id'), 'prohibits:user_id'],
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'rate' => ['required', 'regex:'.Money::INPUT_REGEX],
            'cost' => ['nullable', 'regex:'.Money::INPUT_REGEX],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);

        if (isset($data['workspace_id'], $data['user_id'])) {
            $workspace = Workspace::query()->findOrFail($data['workspace_id']);
            if (! $workspace->members()->whereKey($data['user_id'])->exists()) {
                throw new BillingException('That person is not on this client.');
            }
        }

        RateCard::create([
            'workspace_id' => $data['workspace_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'rate_minor' => Money::parse((string) $data['rate']),
            'cost_minor' => isset($data['cost']) && $data['cost'] !== '' ? Money::parse((string) $data['cost']) : null,
            'effective_from' => $data['effective_from'],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rate added.')]);

        return back();
    }

    public function destroy(RateCard $rateCard): RedirectResponse
    {
        // Safe: invoices keep their own copy of the rate on each line.
        $rateCard->delete();

        return back();
    }
}
