<?php

namespace App\Http\Controllers\People;

use App\Domain\Billing\Money;
use App\Domain\Identity\Permissions;
use App\Domain\People\PeopleReport;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * People report (B-16) for Finance and admins: utilisation, billable %, cost vs value.
 */
class PeopleReportController extends Controller
{
    public function index(Request $request, PeopleReport $report): Response
    {
        [$month, $dept] = $this->params($request);

        return Inertia::render('people/PeopleReport', [
            'month' => $month,
            'report' => $report->month($month, $dept),
            'departments' => Department::query()->orderBy('position')->get(['id', 'name']),
            'filters' => array_filter(['department' => $dept]),
        ]);
    }

    public function csv(Request $request, PeopleReport $report): StreamedResponse
    {
        [$month, $dept] = $this->params($request);
        $data = $report->month($month, $dept);
        $h = fn (int $m): string => number_format($m / 60, 2, '.', '');

        return response()->streamDownload(function () use ($data, $h) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['Person', 'Department', 'Capacity (h)', 'Leave days', 'Logged (h)', 'Billable (h)', 'Billable %', 'Utilisation %', 'Cost (PHP)', 'Value at rates (PHP)', 'Margin (PHP)']);
            foreach ($data['rows'] as $r) {
                fputcsv($out, [
                    $r['name'], $r['department'], $h($r['capacity']), $r['leave_days'], $h($r['logged']), $h($r['billable']),
                    $r['billable_pct'], $r['utilisation_pct'],
                    Money::toDecimal($r['cost_minor']), Money::toDecimal($r['value_minor']), Money::toDecimal($r['margin_minor']),
                ]);
            }
            fclose($out);
        }, "people-{$month}.csv", ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private function params(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->can(Permissions::BILLING_VIEW) || $user->can(Permissions::REPORTS_VIEW_ALL), 403);
        $q = $request->validate(['month' => ['nullable', 'date_format:Y-m'], 'department' => ['nullable', 'integer']]);

        return [$q['month'] ?? now()->format('Y-m'), isset($q['department']) ? (int) $q['department'] : null];
    }
}
