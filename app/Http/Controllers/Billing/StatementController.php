<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\InvoicePdf;
use App\Domain\Billing\StatementService;
use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\BillingProfile;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * E-69: statement of account per client (Finance). PDF for any date range and currency,
 * plus an expiring client link that always shows the last 6 months up to today.
 */
class StatementController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function pdf(Request $request, Workspace $workspace, StatementService $statements, InvoicePdf $pdf): Response
    {
        $data = $request->validate([
            'currency' => ['nullable', Rule::in(BillingProfile::currencies())],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $profile = BillingProfile::query()->firstOrNew(['workspace_id' => $workspace->id]);
        $to = isset($data['to']) ? CarbonImmutable::parse((string) $data['to']) : now()->toImmutable();
        $from = isset($data['from']) ? CarbonImmutable::parse((string) $data['from']) : $to->subMonthsNoOverflow(6)->startOfMonth();
        $statement = $statements->build($workspace, (string) ($data['currency'] ?? $profile->currency), $from, $to);

        return response($pdf->statement($workspace, $statement), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str_replace('"', '', $workspace->name).' statement '.$to->toDateString().'.pdf"',
        ]);
    }

    public function link(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $request->validate(['expires_days' => ['nullable', 'integer', 'in:7,30,90']]);
        $profile = BillingProfile::query()->firstOrCreate(['workspace_id' => $workspace->id]);
        $profile->forceFill([
            'statement_token' => Str::random(40),
            'statement_token_expires_at' => isset($data['expires_days']) ? now()->addDays((int) $data['expires_days']) : null,
        ])->save();
        $this->activity->log('billing.statement-link-created', $workspace, ['expires_days' => $data['expires_days'] ?? null]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Statement link ready. Copy it and send it to the client.')]);

        return back();
    }

    public function unlink(Workspace $workspace): RedirectResponse
    {
        BillingProfile::query()->where('workspace_id', $workspace->id)->update(['statement_token' => null, 'statement_token_expires_at' => null]);
        $this->activity->log('billing.statement-link-disabled', $workspace);

        return back();
    }
}
