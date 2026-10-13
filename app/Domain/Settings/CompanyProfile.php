<?php

namespace App\Domain\Settings;

use Illuminate\Support\Facades\Storage;

/**
 * E-92: the agency's own details for documents (PDF invoices, work reports, the client
 * status page). Admins edit them in the app; .env values (config/billing.php) are only
 * the first-run defaults. The logo is kept on the private disk and embedded into PDFs
 * as a data URI (no remote fetches while rendering).
 */
class CompanyProfile
{
    public const LOGO_DIR = 'branding';

    /** Allowed logo types: DomPDF renders PNG and JPEG reliably. */
    public const LOGO_MIMES = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

    public function __construct(private readonly AppSettings $settings) {}

    /**
     * @return array{name: string, legal_name: string|null, address: string|null, tax_id: string|null, branch_code: string|null, vat_registered: bool, contact: string|null, brand_color: string, invoice_footer: string|null, logo_path: string|null}
     */
    public function get(): array
    {
        $defaults = (array) config('billing.company', []);
        $saved = (array) $this->settings->get('company', []);
        $pick = fn (string $key): ?string => isset($saved[$key]) && is_string($saved[$key]) && trim($saved[$key]) !== ''
            ? $saved[$key]
            : (isset($defaults[$key]) && is_string($defaults[$key]) && trim($defaults[$key]) !== '' ? $defaults[$key] : null);

        $color = $pick('brand_color');

        return [
            'name' => $pick('name') ?? (string) config('app.name', 'OverEasy'),
            'legal_name' => $pick('legal_name'),
            'address' => $pick('address'),
            'tax_id' => $pick('tax_id'),
            'branch_code' => $pick('branch_code'),
            'vat_registered' => (bool) ($saved['vat_registered'] ?? false),
            'contact' => $pick('contact'),
            'brand_color' => $color !== null && preg_match('/^#[0-9a-fA-F]{6}$/', $color) === 1 ? $color : '#1f2937',
            'invoice_footer' => $pick('invoice_footer'),
            'logo_path' => $pick('logo_path'),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        $this->settings->set('company', [...(array) $this->settings->get('company', []), ...$values]);
    }

    /** The logo as a data URI for PDFs and the public status page, or null. */
    public function logoDataUri(): ?string
    {
        $path = $this->get()['logo_path'];
        if ($path === null || ! Storage::disk('local')->exists($path)) {
            return null;
        }
        $bytes = (string) Storage::disk('local')->get($path);
        $mime = str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg';

        return 'data:'.$mime.';base64,'.base64_encode($bytes);
    }
}
