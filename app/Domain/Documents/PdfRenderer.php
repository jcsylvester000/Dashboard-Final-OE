<?php

namespace App\Domain\Documents;

use App\Domain\Files\FileStore;
use App\Domain\Settings\CompanyProfile;
use App\Models\Attachment;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * E-51 / E-52: branded A4 PDFs with "Page X of Y" (same rules as the invoice PDFs),
 * saved as a file on a client, project or task so they appear with its other files.
 */
class PdfRenderer
{
    public function __construct(
        private readonly CompanyProfile $company,
        private readonly FileStore $store,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data): string
    {
        $pdf = Pdf::loadView($view, [...$data, 'company' => $this->company->get(), 'logo' => $this->company->logoDataUri()]);
        $pdf->setPaper('a4');
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 112, $canvas->get_height() - 30, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7.5, [0.61, 0.64, 0.69]);

        return (string) $dompdf->output();
    }

    /** Store the PDF as an attachment on $record (private local disk, signed links). */
    public function save(Model $record, int $workspaceId, string $name, string $bytes, ?User $by): Attachment
    {
        $key = 'attachments/'.$workspaceId.'/'.Str::uuid()->toString().'.pdf';
        $this->store->putContents($key, $bytes);

        return Attachment::create([
            'workspace_id' => $workspaceId,
            'attachable_type' => $record->getMorphClass(),
            'attachable_id' => $record->getKey(),
            'uploaded_by' => $by?->id,
            'disk' => $this->store->name(),
            'key' => $key,
            'original_name' => mb_substr(preg_replace('/[^A-Za-z0-9 ._-]+/', '', $name) ?: 'document', 0, 150).'.pdf',
            'mime' => 'application/pdf',
            'size' => strlen($bytes),
        ]);
    }
}
