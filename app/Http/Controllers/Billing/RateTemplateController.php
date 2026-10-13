<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\Money;
use App\Domain\Billing\RateAudit;
use App\Domain\Billing\RateTemplateService;
use App\Http\Controllers\Controller;
use App\Models\BillingProfile;
use App\Models\Department;
use App\Models\RateTemplate;
use App\Models\RateTemplateItem;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Rate templates (Finance): per-person hourly rates kept as a reusable set and
 * applied to a client, where they become that client's own editable rates.
 */
class RateTemplateController extends Controller
{
    public function __construct(private readonly RateTemplateService $templates) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('rate_templates', 'name')],
            'description' => ['nullable', 'string', 'max:500'],
            'currency' => ['nullable', Rule::in(BillingProfile::currencies())],
            'copy_agency' => ['boolean'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $template = RateTemplate::create(['name' => $data['name'], 'description' => $data['description'] ?? null, 'created_by' => $user->id, 'currency' => $data['currency'] ?? 'PHP']);
        if ($request->boolean('copy_agency')) {
            $this->templates->copyAgencyRates($template);
        }

        return to_route('billing.rate-templates.edit', $template);
    }

    public function edit(RateTemplate $rateTemplate): Response
    {
        $rateTemplate->load('items');
        $byUser = $rateTemplate->items->whereNotNull('user_id')->keyBy('user_id');
        $byDept = $rateTemplate->items->whereNull('user_id')->whereNotNull('department_id')->keyBy('department_id');
        $default = $rateTemplate->items->first(fn (RateTemplateItem $i) => $i->user_id === null && $i->department_id === null);
        $money = fn (?int $minor) => $minor !== null ? Money::toDecimal($minor) : '';

        return Inertia::render('billing/RateTemplate', [
            'template' => $rateTemplate->only(['id', 'name', 'description', 'currency']),
            'people' => User::query()->active()->with('primaryDepartment:id,name')->orderBy('name')->get(['id', 'name', 'title', 'primary_department_id'])
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'title' => $u->title,
                    'department' => $u->primaryDepartment?->name,
                    'rate' => $money($byUser->get($u->id)?->rate_minor),
                    'cost' => $money($byUser->get($u->id)?->cost_minor),
                ])->values(),
            'departments' => Department::query()->orderBy('position')->get(['id', 'name'])
                ->map(fn (Department $d) => [
                    'id' => $d->id,
                    'name' => $d->name,
                    'rate' => $money($byDept->get($d->id)?->rate_minor),
                    'cost' => $money($byDept->get($d->id)?->cost_minor),
                ])->values(),
            'default' => ['rate' => $money($default?->rate_minor), 'cost' => $money($default?->cost_minor)],
        ]);
    }

    public function update(Request $request, RateTemplate $rateTemplate): RedirectResponse
    {
        $money = ['nullable', 'regex:'.Money::INPUT_REGEX];
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('rate_templates', 'name')->ignore($rateTemplate->id)],
            'description' => ['nullable', 'string', 'max:500'],
            'people' => ['array', 'max:1000'],
            'people.*.id' => ['required', 'integer', Rule::exists('users', 'id')],
            'people.*.rate' => $money,
            'people.*.cost' => $money,
            'departments' => ['array', 'max:100'],
            'departments.*.id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'departments.*.rate' => $money,
            'departments.*.cost' => $money,
            'default.rate' => $money,
            'default.cost' => $money,
        ]);

        $rateTemplate->update(['name' => $data['name'], 'description' => $data['description'] ?? null]);

        $items = [];
        foreach ($data['people'] ?? [] as $p) {
            $items[] = ['user_id' => (int) $p['id'], 'rate' => (string) ($p['rate'] ?? ''), 'cost' => $p['cost'] ?? null];
        }
        foreach ($data['departments'] ?? [] as $d) {
            $items[] = ['department_id' => (int) $d['id'], 'rate' => (string) ($d['rate'] ?? ''), 'cost' => $d['cost'] ?? null];
        }
        $items[] = ['rate' => (string) ($data['default']['rate'] ?? ''), 'cost' => $data['default']['cost'] ?? null];
        $before = RateAudit::snapshot($rateTemplate);
        $this->templates->saveItems($rateTemplate, $items);
        app(RateAudit::class)->templateChanged($rateTemplate, $before, RateAudit::snapshot($rateTemplate), $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Template saved. Clients that already use it keep their own copies; apply it again to update them.')]);

        return back();
    }

    public function destroy(RateTemplate $rateTemplate): RedirectResponse
    {
        // Clients keep the rate cards they already got from it.
        $rateTemplate->delete();

        return to_route('billing.rates.index');
    }

    public function apply(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate([
            'rate_template_id' => ['required', 'integer', Rule::exists('rate_templates', 'id')],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $result = $this->templates->apply(RateTemplate::query()->findOrFail($data['rate_template_id']), $workspace, $data['effective_from'], $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __(':n rates applied from :date.', ['n' => $result['created'], 'date' => $data['effective_from']])
                .($result['skipped'] !== [] ? ' '.__('Skipped (not on this client): :names.', ['names' => implode(', ', $result['skipped'])]) : ''),
        ]);

        return back();
    }
}
