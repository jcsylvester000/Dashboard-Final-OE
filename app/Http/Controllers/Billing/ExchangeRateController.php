<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\FxRates;
use App\Http\Controllers\Controller;
use App\Models\BillingProfile;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * E-103: Finance keeps the PHP exchange rates here (e.g. the BSP reference rate each
 * business day or month). Invoices lock the rate of their issue date at finalize,
 * payments the rate of their payment date.
 */
class ExchangeRateController extends Controller
{
    public function index(FxRates $fx): Response
    {
        $currencies = array_values(array_diff(BillingProfile::currencies(), [FxRates::BASE]));
        $today = now()->toImmutable();

        return Inertia::render('billing/ExchangeRates', [
            'currencies' => $currencies,
            'current' => collect($currencies)->mapWithKeys(fn (string $c) => [$c => $fx->rate($c, $today)])->all(),
            'rates' => ExchangeRate::query()->with('author:id,name')->latest('rate_on')->latest('id')->limit(120)->get()
                ->map(fn (ExchangeRate $r): array => [
                    'id' => $r->id, 'currency' => $r->currency, 'rate_on' => $r->rate_on->toDateString(), 'rate' => (string) $r->rate,
                    'note' => $r->note, 'by' => $r->author?->name, 'updated_at' => $r->updated_at?->toIso8601String(),
                ])->values(),
            'today' => $today->toDateString(),
        ]);
    }

    public function store(Request $request, FxRates $fx): RedirectResponse
    {
        $data = $request->validate([
            'currency' => ['required', Rule::in(array_diff(BillingProfile::currencies(), [FxRates::BASE]))],
            'rate_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'rate' => ['required', 'regex:/^\d{1,6}(\.\d{1,6})?$/', 'not_in:0,0.0,0.00'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        /** @var User $user */
        $user = $request->user();
        $fx->set((string) $data['currency'], (string) $data['rate_on'], (string) $data['rate'], $data['note'] ?? null, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':c rate for :d saved.', ['c' => $data['currency'], 'd' => $data['rate_on']])]);

        return back();
    }
}
