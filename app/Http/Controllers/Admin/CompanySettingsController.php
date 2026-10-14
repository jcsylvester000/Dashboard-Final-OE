<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Billing\FinanceSettings;
use App\Domain\Billing\Money;
use App\Domain\Billing\PaymentAccounts;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Settings\CompanyProfile;
use App\Http\Controllers\Controller;
use App\Models\BillingProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
        private readonly FinanceSettings $finance,
    ) {}

    public function edit(): Response
    {
        $c = $this->company->get();

        return Inertia::render('admin/Company', [
            'company' => [...$c, 'has_logo' => $c['logo_path'] !== null],
            'logoUrl' => $c['logo_path'] !== null ? route('admin.company.logo', ['v' => substr(md5($c['logo_path']), 0, 8)]) : null,
            'finance' => $this->finance->get(),
            // E-106: payment details per currency (documents show the one for their currency).
            'paymentAccounts' => app(PaymentAccounts::class)->all(),
            'reviewModes' => FinanceSettings::REVIEW_MODES,
            'currencies' => BillingProfile::currencies(),
        ]);
    }

    public function updateAccounts(Request $request, PaymentAccounts $accounts): RedirectResponse
    {
        $currencies = BillingProfile::currencies();
        $data = $request->validate([
            'accounts' => ['present', 'array'],
            'accounts.*' => ['nullable', 'string', 'max:1000'],
        ]);
        $values = array_intersect_key((array) $data['accounts'], array_flip($currencies));
        $before = $accounts->all();
        $accounts->save($values);
        $after = $accounts->all();
        // Which currencies changed, not the account numbers themselves.
        $changed = array_values(array_filter($currencies, fn ($c) => ($before[$c] ?? null) !== ($after[$c] ?? null)));
        $this->activity->log('settings.payment-accounts-updated', null, ['currencies' => $changed]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Payment details saved. New invoices and statements show the ones for their currency.')]);

        return back();
    }

    /**
     * E-66 / E-67: who must check invoices before they are finalized, and how far back an
     * issue date may go.
     */
    public function updateFinance(Request $request): RedirectResponse
    {
        $currencies = BillingProfile::currencies();
        $data = $request->validate([
            'review' => ['required', Rule::in(array_keys(FinanceSettings::REVIEW_MODES))],
            'thresholds' => ['nullable', 'array'],
            'thresholds.*' => ['nullable', 'regex:'.Money::INPUT_REGEX],
            'backdate_days' => ['required', 'integer', 'min:0', 'max:366'],
            'expense_approval' => ['boolean'],
            'receipt_thresholds' => ['nullable', 'array'],
            'receipt_thresholds.*' => ['nullable', 'regex:'.Money::INPUT_REGEX],
        ]);
        $amounts = function (mixed $raw) use ($currencies): array {
            $out = [];
            foreach ((array) $raw as $currency => $amount) {
                if (in_array($currency, $currencies, true) && $amount !== null && Money::parse((string) $amount) > 0) {
                    $out[(string) $currency] = Money::parse((string) $amount);
                }
            }

            return $out;
        };
        $thresholds = $amounts($data['thresholds'] ?? []);
        if ($data['review'] === 'above' && $thresholds === []) {
            return back()->withErrors(['thresholds' => __('Set an amount for at least one currency.')]);
        }
        $values = [
            'review' => (string) $data['review'],
            'thresholds' => $thresholds,
            'backdate_days' => (int) $data['backdate_days'],
            'expense_approval' => $request->boolean('expense_approval'),
            'receipt_thresholds' => $amounts($data['receipt_thresholds'] ?? []),
        ];
        $before = $this->finance->get();
        $this->finance->save($values);
        $this->activity->log('settings.finance-updated', null, ['before' => $before, 'after' => $values]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Invoice rules saved.')]);

        return back();
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
