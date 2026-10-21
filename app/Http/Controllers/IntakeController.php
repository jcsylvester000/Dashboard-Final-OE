<?php

namespace App\Http\Controllers;

use App\Domain\Forms\FormSubmitter;
use App\Domain\Settings\CompanyProfile;
use App\Http\Controllers\Workspaces\FormController;
use App\Models\Form;
use App\Models\FormVersion;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * E-42: public intake form (no login) at /f/{token}. Spam protection: rate limit,
 * a hidden honeypot field and a minimum fill time. The client gets a reference number;
 * the right lead is alerted; a retried submit returns the same reference.
 */
class IntakeController extends Controller
{
    public const MIN_SECONDS = 3;

    public function __construct(private readonly CompanyProfile $company) {}

    public function show(string $token): View
    {
        [$form, $version] = $this->open($token);
        $company = $this->company->get();

        return view('client.form', [
            'form' => $form,
            'schema' => $version->schema,
            'token' => $token,
            'stamp' => Crypt::encryptString((string) now()->getTimestamp()),
            'key' => (string) Str::uuid(),
            'agency' => $company['name'],
            'brand' => $company['brand_color'],
            'logo' => $this->company->logoDataUri(),
        ]);
    }

    public function submit(Request $request, string $token, FormSubmitter $submitter): RedirectResponse
    {
        [$form] = $this->open($token);
        // Bots fill every field; people never see this one.
        if (trim((string) $request->input('website', '')) !== '') {
            return to_route('intake.thanks', $token)->with('intake_reference', 'REQ-'.strtoupper(Str::random(6)));
        }
        try {
            $started = (int) Crypt::decryptString((string) $request->input('stamp', ''));
        } catch (DecryptException) {
            $started = 0;
        }
        if ($started === 0 || now()->getTimestamp() - $started < self::MIN_SECONDS) {
            throw ValidationException::withMessages(['form' => __('That was quick. Please check your answers and send again.')]);
        }
        $name = trim((string) $request->validate(['submitter_name' => ['required', 'string', 'max:120']])['submitter_name']);

        $submission = $submitter->submit(
            $form,
            (array) $request->input('answers', []),
            FormController::uploads($request),
            null,
            $name,
            is_string($request->input('idempotency_key')) ? mb_substr((string) $request->input('idempotency_key'), 0, 64) : null,
        );

        return to_route('intake.thanks', $token)->with('intake_reference', $submission->reference);
    }

    public function thanks(Request $request, string $token): View
    {
        [$form] = $this->open($token);
        $company = $this->company->get();

        return view('client.form-thanks', [
            'form' => $form,
            'reference' => $request->session()->get('intake_reference'),
            'token' => $token,
            'agency' => $company['name'],
            'brand' => $company['brand_color'],
        ]);
    }

    /**
     * @return array{0: Form, 1: FormVersion}
     */
    private function open(string $token): array
    {
        abort_unless(strlen($token) === 40 && ctype_alnum($token), 404);
        $form = Form::query()->where('public_token', $token)->where('archived', false)->first();
        if ($form === null || $form->current_version_id === null) {
            abort(404);
        }
        $version = FormVersion::query()->find($form->current_version_id);
        if ($version === null) {
            abort(404);
        }

        return [$form, $version];
    }
}
