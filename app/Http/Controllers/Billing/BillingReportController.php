<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingReports;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance reports: revenue by client/month, retainer utilisation, unbilled approved time, profitability by project.
 */
class BillingReportController extends Controller
{
    public function __invoke(Request $request, BillingReports $reports): Response
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $to = CarbonImmutable::parse(($data['to'] ?? now()->format('Y-m')).'-01')->endOfMonth();
        $from = CarbonImmutable::parse(($data['from'] ?? $to->startOfMonth()->subMonths(5)->format('Y-m')).'-01')->startOfMonth();
        if ($from->gt($to)) {
            $from = $to->startOfMonth();
        }
        $month = CarbonImmutable::parse(($data['month'] ?? now()->format('Y-m')).'-01');

        return Inertia::render('billing/Reports', [
            'filters' => ['from' => $from->format('Y-m'), 'to' => $to->format('Y-m'), 'month' => $month->format('Y-m')],
            'revenue' => $reports->revenue($from, $to),
            'utilisation' => $reports->retainerUtilisation($month),
            'unbilled' => $reports->unbilled(),
            'profitability' => $reports->profitability($from, $to),
        ]);
    }
}
