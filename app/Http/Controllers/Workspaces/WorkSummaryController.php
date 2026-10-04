<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Billing\InvoicePdf;
use App\Domain\Billing\WorkReport;
use App\Domain\Billing\WorkSummaryAccess;
use App\Domain\Workspaces\WorkspacePresenter;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Reports\ReportController;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Work Summary (P6-15): who worked on what for a client in any date range,
 * organised by task, with Marketing / SEO filters. Export as CSV or PDF.
 */
class WorkSummaryController extends Controller
{
    public function __construct(private readonly WorkReport $report) {}

    public function index(Request $request, Workspace $workspace, WorkspacePresenter $presenter): Response
    {
        [$from, $to, $dept] = $this->filters($request, $workspace);

        return Inertia::render('workspaces/WorkSummary', [
            'workspace' => $presenter->header($workspace, $this->user($request)),
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'department' => $dept],
            'summary' => $this->report->forPeriod($workspace->id, $from, $to, $dept),
            'departments' => DB::table('departments')->orderBy('position')->get(['slug', 'name']),
        ]);
    }

    public function csv(Request $request, Workspace $workspace): StreamedResponse
    {
        [$from, $to, $dept] = $this->filters($request, $workspace);
        $summary = $this->report->forPeriod($workspace->id, $from, $to, $dept);

        $name = sprintf('work-summary-%s-%s-to-%s.csv', $workspace->slug, $from->toDateString(), $to->toDateString());

        return response()->streamDownload(function () use ($summary) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Task', 'Department', 'Status', 'Person', 'Hours', 'Campaign', 'Channel', 'Deliverable', 'Target URL', 'Keyword', 'SEO work type'], escape: '');
            foreach ($summary['tasks'] as $task) {
                $d = (array) $task['work_details'];
                foreach ($task['people'] as $person) {
                    fputcsv($out, array_map(ReportController::safeCell(...), [
                        $task['title'], $task['department'], $task['status'] ?? '', $person['name'],
                        sprintf('%d.%02d', intdiv($person['minutes'], 60), intdiv(($person['minutes'] % 60) * 100 + 30, 60)),
                        $d['campaign'] ?? '', $d['channel'] ?? '', $d['deliverable_type'] ?? '',
                        $d['target_url'] ?? '', $d['keyword'] ?? '', $d['work_type'] ?? '',
                    ]), escape: '');
                }
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function pdf(Request $request, Workspace $workspace, InvoicePdf $pdf): HttpResponse
    {
        [$from, $to, $dept] = $this->filters($request, $workspace);

        $bytes = $pdf->renderWorkReport([
            'title' => 'Work summary'.($dept !== null ? ' – '.DB::table('departments')->where('slug', $dept)->value('name') : ''),
            'client' => $workspace->name,
            'period' => $from->format('M j, Y').' – '.$to->format('M j, Y'),
            'ref' => 'Generated '.now()->format('M j, Y'),
        ], $this->report->forPeriod($workspace->id, $from, $to, $dept));

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="work-summary-%s-%s.pdf"', $workspace->slug, $from->format('Y-m')),
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string|null}
     */
    private function filters(Request $request, Workspace $workspace): array
    {
        abort_unless(WorkSummaryAccess::allows($this->user($request), $workspace), 403);

        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'department' => ['nullable', Rule::exists('departments', 'slug')],
        ]);

        $to = isset($data['to']) ? CarbonImmutable::parse($data['to']) : now()->toImmutable();
        $from = isset($data['from']) ? CarbonImmutable::parse($data['from']) : $to->startOfMonth();
        if ($from->diffInDays($to) > 366) {
            $from = $to->subDays(366);
        }

        return [$from, $to, $data['department'] ?? null];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
