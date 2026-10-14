<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingReports;
use App\Domain\Billing\FxRates;
use App\Domain\Exports\ExportTable;
use App\Domain\Exports\ReportExporter;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * Finance reports: revenue by client/month, retainer utilisation, unbilled approved time,
 * profitability by project, aging and budget vs actual. E-81: each one downloads as CSV,
 * Excel or PDF with the filters printed on it.
 */
class BillingReportController extends Controller
{
    /** report key => title */
    public const EXPORTS = [
        'revenue' => 'Revenue by client',
        'utilisation' => 'Retainer utilisation',
        'unbilled' => 'Unbilled approved time',
        'profitability' => 'Profitability by project',
        'aging' => 'Receivables aging',
        'budgets' => 'Budget vs actual',
    ];

    public function __invoke(Request $request, BillingReports $reports): Response
    {
        [$from, $to, $month] = $this->period($request);

        return Inertia::render('billing/Reports', [
            'filters' => ['from' => $from->format('Y-m'), 'to' => $to->format('Y-m'), 'month' => $month->format('Y-m')],
            'revenue' => $reports->revenue($from, $to),
            'utilisation' => $reports->retainerUtilisation($month),
            'unbilled' => $reports->unbilled(),
            'profitability' => $reports->profitability($from, $to),
            // E-107: exchange gains/losses and the base currency.
            'fx' => $reports->foreignExchange($from->startOfMonth(), $to),
            'base' => FxRates::BASE,
            // E-76: budget vs actual.
            'budgets' => $reports->budgets(),
            'exports' => self::EXPORTS,
        ]);
    }

    public function export(Request $request, string $report, string $format, BillingReports $reports, ReportExporter $exporter): BaseResponse
    {
        abort_unless(isset(self::EXPORTS[$report]) && in_array($format, ReportExporter::FORMATS, true), 404);
        [$from, $to, $month] = $this->period($request);
        $range = ['Period' => $from->format('M Y').' to '.$to->format('M Y')];
        $text = fn (string $key, string $label) => ['key' => $key, 'label' => $label, 'type' => 'text'];
        $money = fn (string $key, string $label, ?string $currency = null) => ['key' => $key, 'label' => $label, 'type' => 'money', 'currency' => $currency, 'sum' => $currency !== null];
        $hours = fn (string $key, string $label) => ['key' => $key, 'label' => $label, 'type' => 'hours'];
        $pct = fn (string $key, string $label) => ['key' => $key, 'label' => $label, 'type' => 'percent'];
        $h = fn (int $m): float => round($m / 60, 2);
        $base = FxRates::BASE;

        $table = match ($report) {
            'revenue' => new ExportTable(self::EXPORTS[$report], $range, [
                $text('client', 'Client'), $text('month', 'Month'), $text('currency', 'Currency'),
                $money('net_minor', 'Net'), $money('tax_minor', 'Tax'), $money('total_minor', 'Total'), $money('paid_minor', 'Paid'),
                $money('net_php_minor', 'Net', $base),
            ], $reports->revenue($from, $to), 'net_php_minor'),
            'utilisation' => new ExportTable(self::EXPORTS[$report], ['Month' => $month->format('M Y')], [
                $text('client', 'Client'), $hours('included', 'Included (h)'), $hours('used', 'Used (h)'), $pct('utilisation_pct', 'Utilisation'),
            ], array_map(fn ($r) => ['client' => $r['client'], 'included' => $h($r['included_minutes']), 'used' => $h($r['used_minutes']), 'utilisation_pct' => $r['utilisation_pct']],
                $reports->retainerUtilisation($month)), 'utilisation_pct'),
            'unbilled' => new ExportTable(self::EXPORTS[$report], ['As of' => now()->format('M j, Y')], [
                $text('client', 'Client'), $text('currency', 'Currency'), $hours('hours', 'Hours'), $hours('unpriced', 'Without a rate (h)'),
                $money('value_minor', 'Value'), $money('value_php_minor', 'Value (today\'s rate)', $base), $text('oldest', 'Oldest entry'),
            ], array_map(fn ($r) => [...$r, 'hours' => $h($r['minutes']), 'unpriced' => $h($r['unpriced_minutes'])], $reports->unbilled()), 'hours'),
            'profitability' => new ExportTable(self::EXPORTS[$report], $range, [
                $text('client', 'Client'), $text('project', 'Project'), $text('currency', 'Currency'), $hours('hours', 'Hours'),
                $money('revenue_minor', 'Revenue'), $money('cost_minor', 'Cost'), $money('margin_minor', 'Margin'),
                $money('revenue_php_minor', 'Revenue', $base), $money('cost_php_minor', 'Cost', $base), $money('margin_php_minor', 'Margin', $base),
            ], array_map(fn ($r) => [...$r, 'hours' => $h($r['minutes'])], $reports->profitability($from, $to)), 'margin_php_minor', 'project'),
            'aging' => new ExportTable(self::EXPORTS[$report], ['As of' => now()->format('M j, Y')], [
                $text('client', 'Client'), $text('number', 'Invoice'), $text('currency', 'Currency'), $text('issue_date', 'Issued'), $text('due_date', 'Due'),
                ['key' => 'days_overdue', 'label' => 'Days overdue', 'type' => 'number', 'sum' => false], $text('bucket', 'Bucket'),
                $money('balance_minor', 'Balance'), $money('balance_php_minor', 'Balance', $base),
            ], $reports->agingRows(), 'balance_php_minor', 'number'),
            default => new ExportTable(self::EXPORTS[$report], ['As of' => now()->format('M j, Y')], [
                $text('client', 'Client'), $text('project', 'Project'), $text('currency', 'Currency'),
                ['key' => 'budget_hours', 'label' => 'Budget (h)', 'type' => 'hours'], $hours('used_hours', 'Used (h)'), $pct('hours_pct', 'Hours used'),
                $money('budget_minor', 'Budget'), $money('used_minor', 'Used'), $pct('amount_pct', 'Amount used'),
            ], $reports->budgets(), 'hours_pct', 'project'),
        };

        return $exporter->respond($table, $format);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable}
     */
    private function period(Request $request): array
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

        return [$from, $to, CarbonImmutable::parse(($data['month'] ?? now()->format('Y-m')).'-01')];
    }
}
