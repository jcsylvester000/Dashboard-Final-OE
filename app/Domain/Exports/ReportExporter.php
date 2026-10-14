<?php

namespace App\Domain\Exports;

use App\Domain\Settings\CompanyProfile;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * E-49 / E-81: every report downloads as CSV, Excel (.xlsx) or a branded PDF from the
 * same ExportTable, so the three always show the same numbers.
 */
class ReportExporter
{
    public const FORMATS = ['csv', 'xlsx', 'pdf'];

    public function __construct(private readonly CompanyProfile $company) {}

    public function respond(ExportTable $t, string $format): BaseResponse
    {
        return match ($format) {
            'xlsx' => $this->download((new XlsxWriter)->make($t), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $t->fileName('xlsx')),
            'pdf' => $this->download($this->pdf($t), 'application/pdf', $t->fileName('pdf')),
            default => $this->download($this->csv($t), 'text/csv; charset=UTF-8', $t->fileName('csv')),
        };
    }

    public function csv(ExportTable $t): string
    {
        $out = fopen('php://temp', 'r+');
        if ($out === false) {
            return '';
        }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, array_map(fn ($c) => $c['label'].(($c['type'] === 'money' && ($c['currency'] ?? null)) ? ' ('.$c['currency'].')' : ''), $t->columns), escape: '');
        foreach ($t->rows as $row) {
            fputcsv($out, array_map(fn ($c) => ExportTable::plain($row[$c['key']] ?? null, $c['type']), $t->columns), escape: '');
        }
        rewind($out);
        $bytes = (string) stream_get_contents($out);
        fclose($out);

        return $bytes;
    }

    public function pdf(ExportTable $t): string
    {
        $totals = [];
        foreach ($t->columns as $c) {
            if ($t->sums($c)) {
                $totals[$c['key']] = array_sum(array_map(fn ($r) => is_numeric($r[$c['key']] ?? null) ? (float) $r[$c['key']] : 0.0, $t->rows));
            }
        }
        $chart = [];
        if ($t->chartKey !== null && $t->rows !== []) {
            $type = collect($t->columns)->firstWhere('key', $t->chartKey)['type'] ?? 'number';
            $max = max(1, ...array_map(fn ($r) => abs((float) ($r[$t->chartKey] ?? 0)), $t->rows));
            foreach (array_slice($t->rows, 0, 15) as $r) {
                $v = (float) ($r[$t->chartKey] ?? 0);
                $chart[] = ['label' => (string) ($r[$t->labelKey ?? $t->columns[0]['key']] ?? ''), 'value' => self::show($v, $type), 'pct' => round(abs($v) / $max * 100, 1), 'neg' => $v < 0];
            }
        }
        $company = $this->company->get();
        $pdf = Pdf::loadView('pdf.report', [
            't' => $t,
            'totals' => $totals,
            'chart' => $chart,
            'chartLabel' => collect($t->columns)->firstWhere('key', $t->chartKey)['label'] ?? '',
            'company' => $company,
            'logo' => $this->company->logoDataUri(),
        ]);
        $pdf->setPaper('a4', count($t->columns) > 7 ? 'landscape' : 'portrait');
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 112, $canvas->get_height() - 30, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.61, 0.64, 0.69]);

        return (string) $dompdf->output();
    }

    /** A cell as text for the PDF. */
    public static function show(mixed $value, string $type): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if (! is_numeric($value)) {
            return (string) $value;
        }

        return match ($type) {
            'money' => number_format(((int) $value) / 100, 2),
            'percent' => number_format((float) $value, 1).'%',
            'hours', 'days' => number_format((float) $value, 2),
            'number' => number_format((float) $value),
            default => (string) $value,
        };
    }

    private function download(string $bytes, string $type, string $name): Response
    {
        return response($bytes, 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
