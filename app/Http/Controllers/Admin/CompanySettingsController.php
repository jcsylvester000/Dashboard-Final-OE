<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Settings\CompanyProfile;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * E-92: company details and logo for documents (admins and Finance).
 */
class CompanySettingsController extends Controller
{
    public function __construct(
        private readonly CompanyProfile $company,
        private readonly ActivityLogger $activity,
    ) {}

    public function edit(): Response
    {
        $c = $this->company->get();

        return Inertia::render('admin/Company', [
            'company' => [...$c, 'has_logo' => $c['logo_path'] !== null],
            'logoUrl' => $c['logo_path'] !== null ? route('admin.company.logo', ['v' => substr(md5($c['logo_path']), 0, 8)]) : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:500'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'branch_code' => ['nullable', 'string', 'max:10'],
            'vat_registered' => ['boolean'],
            'contact' => ['nullable', 'string', 'max:200'],
            'brand_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'invoice_footer' => ['nullable', 'string', 'max:500'],
        ]);
        $before = $this->company->get();
        $this->company->save([...$data, 'vat_registered' => $request->boolean('vat_registered')]);
        $after = $this->company->get();
        $changes = array_keys(array_filter($data, fn ($v, $k) => ($before[$k] ?? null) != ($after[$k] ?? null), ARRAY_FILTER_USE_BOTH));
        $this->activity->log('settings.company-updated', null, ['changed' => $changes]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Company details saved. New PDFs use them; finalized invoices keep their stored copy.')]);

        return back();
    }

    public function uploadLogo(Request $request): RedirectResponse
    {
        $request->validate([
            'logo' => ['required', 'file', 'max:1024', 'mimetypes:'.implode(',', array_keys(CompanyProfile::LOGO_MIMES)), 'dimensions:max_width=3000,max_height=3000'],
        ]);
        $file = $request->file('logo');
        abort_if($file === null || is_array($file), 422);
        $ext = CompanyProfile::LOGO_MIMES[(string) $file->getMimeType()] ?? 'png';

        $old = $this->company->get()['logo_path'];
        $path = $file->storeAs(CompanyProfile::LOGO_DIR, 'logo-'.Str::lower(Str::random(8)).'.'.$ext, 'local');
        abort_if($path === false, 500, 'Could not store the logo.');
        $this->company->save(['logo_path' => $path]);
        if ($old !== null && $old !== $path) {
            Storage::disk('local')->delete($old);
        }
        $this->activity->log('settings.logo-uploaded', null, ['path' => $path]);

        return back();
    }

    public function removeLogo(): RedirectResponse
    {
        $old = $this->company->get()['logo_path'];
        $this->company->save(['logo_path' => null]);
        if ($old !== null) {
            Storage::disk('local')->delete($old);
        }
        $this->activity->log('settings.logo-removed');

        return back();
    }

    /** Preview for the settings page (signed-in admins only). */
    public function logo(): HttpResponse
    {
        $path = $this->company->get()['logo_path'];
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff']);
    }
}
