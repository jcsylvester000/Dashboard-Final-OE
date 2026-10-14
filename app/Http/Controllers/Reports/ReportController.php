<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Exports\ExportTable;
use App\Domain\Exports\ReportExporter;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\ReportService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;
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
            // S1-27: project filter, limited to clients the user may report on.
            'projects' => DB::table('projects')
                ->whereNull('deleted_at')->where('status', '!=', 'archived')
                ->when($allowed !== null, fn ($q) => $q->whereIn('workspace_id', $allowed ?? []))
                ->orderBy('name')
                ->get(['id', 'name', 'workspace_id'])
                ->map(fn ($p) => ['id' => (int) $p->id, 'name' => (string) $p->name, 'workspace_id' => (int) $p->workspace_id])
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
     * E-49: the same report as CSV, Excel or a branded PDF (filters listed, chart of the first number).
     */
    public function export(Request $request, string $format, ReportExporter $exporter): BaseResponse
    {
        abort_unless(in_array($format, ReportExporter::FORMATS, true), 404);
        /** @var User $user */
        $user = $request->user();
        $filters = ReportFilters::fromRequest($request, $user);
        [$report, $group] = $this->choice($request);
        $result = $this->reports->run($report, $filters, $group);

        $labels = ['Period' => $filters->from->toDateString().' to '.$filters->to->toDateString()];
        if ($filters->workspaceId !== null) {
            $labels['Client'] = (string) DB::table('workspaces')->where('id', $filters->workspaceId)->value('name');
        }
        if ($filters->departmentId !== null) {
            $labels['Department'] = (string) DB::table('departments')->where('id', $filters->departmentId)->value('name');
        }
        if ($filters->projectId !== null) {
            $labels['Project'] = (string) DB::table('projects')->where('id', $filters->projectId)->value('name');
        }
        if ($report === 'time') {
            $labels['Grouped by'] = ReportService::TIME_GROUPS[$group];
        }

        $columns = $result['columns'];
        $chart = collect($columns)->first(fn ($c) => in_array($c['type'], ['number', 'hours', 'days'], true));

        return $exporter->respond(new ExportTable(
            ReportService::REPORTS[$report],
            $labels,
            $columns,
            $result['rows'],
            $chart['key'] ?? null,
        ), $format);
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

        $first = ltrim($text, " \n")[0] ?? '';

        return in_array($first, ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
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
