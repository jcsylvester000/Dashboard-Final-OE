<?php

namespace App\Domain\Files;

use App\Domain\Settings\CompanyProfile;
use App\Models\Annotation;
use App\Models\Attachment;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * E-09: a printable record of one version's review round: the image with its numbered pins
 * and drawings, then one row per comment thread (who, what, replies, open or resolved).
 */
class FeedbackSheet
{
    /** Width of the image on the page (A4 portrait content width), in px. */
    private const WIDTH = 515;

    public function __construct(
        private readonly FileStore $store,
        private readonly CompanyProfile $company,
    ) {}

    public function pdf(Attachment $attachment): string
    {
        $attachment->loadMissing('attachable');
        $threads = Annotation::query()->with(['author:id,name', 'resolver:id,name', 'replies.author:id,name'])
            ->where('attachment_id', $attachment->id)->whereNull('parent_id')->orderBy('number')->get();

        // The image itself when it is on our disk (else its thumbnail), with the pins drawn over it.
        $bytes = $attachment->disk !== 'uploadthing' ? $this->store->contents($attachment->key) : null;
        $bytes ??= $attachment->thumbnail_key !== null ? $this->store->contents($attachment->thumbnail_key) : null;
        $image = null;
        $overlay = null;
        $height = 0;
        if ($bytes !== null) {
            $size = @getimagesizefromstring($bytes);
            if (is_array($size) && $size[0] > 0) {
                $height = (int) round(self::WIDTH * $size[1] / $size[0]);
                $image = 'data:'.$size['mime'].';base64,'.base64_encode($bytes);
                $overlay = 'data:image/svg+xml;base64,'.base64_encode($this->svg($threads->all(), self::WIDTH, $height));
            }
        }

        $company = $this->company->get();
        $pdf = Pdf::loadView('pdf.feedback', [
            'attachment' => $attachment,
            'task' => $attachment->attachable,
            'threads' => $threads,
            'image' => $image,
            'overlay' => $overlay,
            'width' => self::WIDTH,
            'height' => $height,
            'company' => $company,
            'logo' => $this->company->logoDataUri(),
        ]);
        $pdf->setPaper('a4');
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 112, $canvas->get_height() - 30, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.61, 0.64, 0.69]);

        return (string) $dompdf->output();
    }

    /**
     * Pins (numbered circles) and drawings as SVG at the image's size on the page.
     *
     * @param  list<Annotation>  $threads
     */
    public function svg(array $threads, int $w, int $h): string
    {
        $out = '<svg xmlns="http://www.w3.org/2000/svg" width="'.$w.'" height="'.$h.'" viewBox="0 0 '.$w.' '.$h.'">';
        $esc = fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        foreach ($threads as $a) {
            $color = $esc($a->color ?? Annotation::COLORS[0]);
            foreach ($a->shapes ?? [] as $shape) {
                $points = array_map(fn ($p) => [(float) ($p[0] ?? 0) * $w, (float) ($p[1] ?? 0) * $h], (array) ($shape['points'] ?? []));
                if ($points === []) {
                    continue;
                }
                $stroke = $esc((string) ($shape['color'] ?? $color));
                $width = (int) ($shape['width'] ?? 3);
                [$x1, $y1] = $points[0];
                [$x2, $y2] = $points[count($points) - 1];
                $out .= match ((string) ($shape['type'] ?? 'pen')) {
                    'rect' => sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" fill="none" stroke="%s" stroke-width="%d"/>', min($x1, $x2), min($y1, $y2), abs($x2 - $x1), abs($y2 - $y1), $stroke, $width),
                    'ellipse' => sprintf('<ellipse cx="%.1f" cy="%.1f" rx="%.1f" ry="%.1f" fill="none" stroke="%s" stroke-width="%d"/>', ($x1 + $x2) / 2, ($y1 + $y2) / 2, abs($x2 - $x1) / 2, abs($y2 - $y1) / 2, $stroke, $width),
                    'arrow' => sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="%d"/><circle cx="%.1f" cy="%.1f" r="%d" fill="%s"/>', $x1, $y1, $x2, $y2, $stroke, $width, $x2, $y2, $width + 2, $stroke),
                    'text' => sprintf('<text x="%.1f" y="%.1f" font-size="12" fill="%s">%s</text>', $x1, $y1, $stroke, $esc((string) ($shape['text'] ?? ''))),
                    default => '<polyline fill="none" stroke="'.$stroke.'" stroke-width="'.$width.'" points="'.implode(' ', array_map(fn ($p) => sprintf('%.1f,%.1f', $p[0], $p[1]), $points)).'"/>',
                };
            }
            if ($a->x !== null && $a->y !== null && $a->number !== null) {
                $cx = (float) $a->x * $w;
                $cy = (float) $a->y * $h;
                $out .= sprintf('<circle cx="%.1f" cy="%.1f" r="9" fill="%s" stroke="#ffffff" stroke-width="2"/>', $cx, $cy, $color);
                $out .= sprintf('<text x="%.1f" y="%.1f" font-size="10" font-weight="bold" text-anchor="middle" fill="#ffffff">%d</text>', $cx, $cy + 3.5, $a->number);
            }
        }

        return $out.'</svg>';
    }
}
