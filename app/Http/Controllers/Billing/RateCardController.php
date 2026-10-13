<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\Money;
use App\Domain\Billing\RateAudit;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\BillingProfile;
use App\Models\Department;
use App\Models\RateCard;
use App\Models\RateTemplate;
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
            'templates' => RateTemplate::query()->withCount('items')->orderBy('name')->get(['id', 'name', 'description', 'updated_at'])
                ->map(fn (RateTemplate $t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'items' => (int) $t->getAttribute('items_count'),
                    'clients' => BillingProfile::query()->where('rate_template_id', $t->id)->count(),
                ])->values(),
        ]);
    }

    /**
     * Who changed which rate, when, from what to what (agency, client and template rates).
     */
    public function history(Request $request): Response
    {
        $filters = $request->validate(['person' => ['nullable', 'integer'], 'scope' => ['nullable', Rule::in(['agency', 'client', 'template'])]]);
        $userMorph = (new User)->getMorphClass();

        $page = ActivityLog::query()
            ->with('actor:id,name')
            ->whereIn('action', RateAudit::ACTIONS)
            ->when($filters['person'] ?? null, fn ($q, $id) => $q->where('subject_type', $userMorph)->where('subject_id', (int) $id))
            ->when($filters['scope'] ?? null, fn ($q, $scope) => $q->where('properties->scope', (string) $scope))
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        $people = User::query()->whereIn('id', $page->getCollection()->filter(fn (ActivityLog $l) => $l->subject_type === $userMorph)->pluck('subject_id'))->pluck('name', 'id');
        $departments = Department::query()->pluck('name', 'id');
        $money = fn (mixed $v): ?string => $v === null ? null : Money::format((int) $v);

        return Inertia::render('billing/RateHistory', [
            'entries' => $page->through(function (ActivityLog $l) use ($people, $departments, $money): array {
                $p = (array) ($l->properties ?? []);
                $scope = (string) ($p['scope'] ?? '');

                return [
                    'id' => $l->id,
                    'at' => $l->created_at->toIso8601String(),
                    'actor' => $l->actor->name ?? 'System', // ?? also covers a deleted actor (null relation)
                    'action' => $l->action,
                    'scope' => $scope,
                    'where' => (string) ($p['scope_label'] ?? ''),
                    'for' => isset($p['user_id']) ? ($people[(int) $p['user_id']] ?? 'Former member') : (isset($p['department_id']) ? ($departments[(int) $p['department_id']] ?? 'Department').' (department)' : 'Default rate'),
                    'from' => $money($p['from_minor'] ?? null),
                    'from_note' => $p['from_note'] ?? null,
                    'to' => $money($p['to_minor'] ?? null),
                    'cost_from' => $money($p['cost_from_minor'] ?? null),
                    'cost_to' => $money($p['cost_to_minor'] ?? null),
                    'effective_from' => $p['effective_from'] ?? null,
                    'via' => $p['via'] ?? null,
                ];
            }),
            'people' => User::query()->orderBy('name')->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request, RateAudit $audit): RedirectResponse
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

        $card = RateCard::create([
            'workspace_id' => $data['workspace_id'] ?? null,
            'department_id' => $data['department_id'] ?? null,
            'user_id' => $data['user_id'] ?? null,
            'rate_minor' => Money::parse((string) $data['rate']),
            'cost_minor' => isset($data['cost']) && $data['cost'] !== '' ? Money::parse((string) $data['cost']) : null,
            'effective_from' => $data['effective_from'],
        ]);

        $audit->cardAdded($card, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rate added.')]);

        return back();
    }

    public function destroy(Request $request, RateCard $rateCard, RateAudit $audit): RedirectResponse
    {
        // Safe: invoices keep their own copy of the rate on each line.
        $rateCard->delete();
        $audit->cardRemoved($rateCard, $request->user());

        return back();
    }
}
