<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Reports for workspace leads (their workspaces) and admins (all).
 * Every report can be filtered by workspace, department and dates, and exported as CSV.
 */
class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filters = ReportFilters::fromRequest($request, $user);
        [$report, $group] = $this->choice($request);

        $allowed = $filters->allowedWorkspaceIds;

        return Inertia::render('reports/Index', [
            'report' => $report,
            'group' => $group,
            'filters' => $filters->toArray(),
            'result' => $this->reports->run($report, $filters, $group),
            'reports' => ReportService::REPORTS,
            'groups' => ReportService::TIME_GROUPS,
            'workspaces' => DB::table('workspaces')
                ->whereNull('deleted_at')
                ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed ?? []))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($w) => ['id' => (int) $w->id, 'name' => (string) $w->name])
                ->values(),
            'departments' => DB::table('departments')->orderBy('position')->get(['id', 'name'])
                ->map(fn ($d) => ['id' => (int) $d->id, 'name' => (string) $d->name])
                ->values(),
        ]);
    }

    /**
     * Same report as CSV (UTF-8 with BOM so Excel opens it correctly).
     */
    public function csv(Request $request): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $filters = ReportFilters::fromRequest($request, $user);
        [$report, $group] = $this->choice($request);
        $result = $this->reports->run($report, $filters, $group);

        $name = sprintf('%s-%s-to-%s.csv', str_replace('_', '-', $report), $filters->from->toDateString(), $filters->to->toDateString());

        return response()->streamDownload(function () use ($result) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_column($result['columns'], 'label'), escape: '');
            foreach ($result['rows'] as $row) {
                $line = [];
                foreach ($result['columns'] as $col) {
                    $line[] = self::safeCell($row[$col['key']] ?? '');
                }
                fputcsv($out, $line, escape: '');
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Stop spreadsheet formula injection from task titles and names.
     */
    public static function safeCell(mixed $value): string|int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function choice(Request $request): array
    {
        $data = $request->validate([
            'report' => ['nullable', Rule::in(array_keys(ReportService::REPORTS))],
            'group' => ['nullable', Rule::in(array_keys(ReportService::TIME_GROUPS))],
        ]);

        return [(string) ($data['report'] ?? 'throughput'), (string) ($data['group'] ?? 'person')];
    }
}
