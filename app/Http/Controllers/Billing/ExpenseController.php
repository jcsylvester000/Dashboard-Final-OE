<?php

namespace App\Http\Controllers\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\FinanceSettings;
use App\Domain\Billing\Money;
use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\BillingProfile;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * E-75: client expenses (pass-through costs). Category, handling markup, tax flag, a private
 * receipt, editing until billed, and an optional second-person approval before billing.
 */
class ExpenseController extends Controller
{
    /** Receipts: PDFs and photos, kept on the private disk, never public. */
    private const RECEIPT_MIMES = 'pdf,jpg,jpeg,png,webp';

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly FinanceSettings $finance,
    ) {}

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $data = $this->validated($request, $workspace);
        $amount = Money::parse((string) $data['amount']);
        $receipt = $request->file('receipt');
        $this->requireReceipt($workspace, $amount, $receipt instanceof UploadedFile);

        $expense = Expense::create([
            'workspace_id' => $workspace->id,
            'project_id' => $data['project_id'] ?? null,
            'description' => $data['description'],
            'amount_minor' => $amount,
            'incurred_on' => $data['incurred_on'],
            'is_billable' => (bool) ($data['is_billable'] ?? true),
            'category' => $data['category'] ?? null,
            'markup_bp' => isset($data['markup']) ? Money::parse((string) $data['markup']) : 0,
            'taxable' => (bool) ($data['taxable'] ?? true),
            'created_by' => $request->user()?->id,
        ]);
        if ($receipt instanceof UploadedFile) {
            $this->storeReceipt($expense, $receipt);
        }
        $this->activity->log('billing.expense-added', $workspace, ['expense_id' => $expense->id, 'description' => $expense->description, 'amount_minor' => $expense->amount_minor,
            'markup_bp' => $expense->markup_bp, 'billable' => $expense->is_billable, 'approved' => $expense->isApproved()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => $expense->isApproved()
            ? __('Expense added. It goes on the next invoice.')
            : __('Expense added. Someone else in Finance approves it before it is billed.')]);

        return back();
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $this->assertNotBilled($expense);
        $workspace = Workspace::query()->withTrashed()->findOrFail($expense->workspace_id);
        $data = $this->validated($request, $workspace);
        $amount = Money::parse((string) $data['amount']);
        $receipt = $request->file('receipt');
        $this->requireReceipt($workspace, $amount, $receipt instanceof UploadedFile || $expense->receipt_path !== null);

        $before = $expense->only(['description', 'amount_minor', 'incurred_on', 'category', 'markup_bp', 'taxable', 'is_billable', 'project_id']);
        $expense->fill([
            'project_id' => $data['project_id'] ?? null,
            'description' => $data['description'],
            'amount_minor' => $amount,
            'incurred_on' => $data['incurred_on'],
            'is_billable' => (bool) ($data['is_billable'] ?? true),
            'category' => $data['category'] ?? null,
            'markup_bp' => isset($data['markup']) ? Money::parse((string) $data['markup']) : 0,
            'taxable' => (bool) ($data['taxable'] ?? true),
        ]);
        // A changed amount needs a fresh check when approvals are on.
        if ($expense->isDirty(['amount_minor', 'markup_bp', 'is_billable']) && $this->finance->get()['expense_approval']) {
            $expense->forceFill(['approved_at' => null, 'approved_by' => null]);
        }
        $expense->save();
        if ($receipt instanceof UploadedFile) {
            $this->storeReceipt($expense, $receipt);
        }
        $this->activity->log('billing.expense-updated', $workspace, ['expense_id' => $expense->id, 'before' => $before,
            'after' => $expense->only(array_keys($before))]);

        return back();
    }

    public function approve(Request $request, Expense $expense): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($expense->isApproved()) {
            throw new BillingException('This expense is already approved.');
        }
        if ($expense->created_by === $user->id) {
            throw new BillingException('Someone else in Finance approves the expenses you add.');
        }
        $expense->forceFill(['approved_at' => now(), 'approved_by' => $user->id])->save();
        $this->activity->log('billing.expense-approved', null, ['workspace_id' => $expense->workspace_id, 'expense_id' => $expense->id, 'amount_minor' => $expense->amount_minor]);

        return back();
    }

    public function receipt(Expense $expense): StreamedResponse
    {
        abort_if($expense->receipt_path === null || ! Storage::disk('local')->exists($expense->receipt_path), 404);

        return Storage::disk('local')->download($expense->receipt_path, $expense->receipt_name ?? 'receipt');
    }

    public function destroy(Expense $expense): RedirectResponse
    {
        $this->assertNotBilled($expense);
        $expense->delete();
        if ($expense->receipt_path !== null) {
            Storage::disk('local')->delete($expense->receipt_path);
        }
        $this->activity->log('billing.expense-deleted', null, ['workspace_id' => $expense->workspace_id, 'expense_id' => $expense->id, 'description' => $expense->description, 'amount_minor' => $expense->amount_minor]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, Workspace $workspace): array
    {
        return $request->validate([
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'regex:'.Money::INPUT_REGEX],
            'incurred_on' => ['required', 'date_format:Y-m-d'],
            'is_billable' => ['boolean'],
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('workspace_id', $workspace->id)],
            'category' => ['nullable', Rule::in(array_keys(Expense::CATEGORIES))],
            'markup' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'], // percent
            'taxable' => ['boolean'],
            'receipt' => ['nullable', 'file', 'max:10240', 'mimes:'.self::RECEIPT_MIMES],
        ]);
    }

    private function requireReceipt(Workspace $workspace, int $amount, bool $has): void
    {
        $currency = BillingProfile::query()->where('workspace_id', $workspace->id)->value('currency') ?? 'PHP';
        if (! $has && $this->finance->needsReceipt((string) $currency, $amount)) {
            throw new BillingException(__('Attach the receipt: it is required from :a.', ['a' => Money::format($this->finance->get()['receipt_thresholds'][(string) $currency] ?? 0, (string) $currency)]));
        }
    }

    private function storeReceipt(Expense $expense, UploadedFile $file): void
    {
        $old = $expense->receipt_path;
        $path = $file->storeAs('receipts/'.$expense->workspace_id, Str::lower(Str::random(24)).'.'.$file->guessExtension(), 'local');
        abort_if($path === false, 500, 'Could not store the receipt.');
        $expense->forceFill(['receipt_path' => $path, 'receipt_name' => Str::limit($file->getClientOriginalName(), 200, '')])->save();
        if ($old !== null && $old !== $path) {
            Storage::disk('local')->delete($old);
        }
    }

    /** Expenses on an invoice (or a draft) are frozen; void the invoice or delete the draft first. */
    private function assertNotBilled(Expense $expense): void
    {
        $onInvoice = DB::table('invoice_item_sources as s')
            ->join('invoice_items as i', 'i.id', '=', 's.invoice_item_id')
            ->join('invoices as v', 'v.id', '=', 'i.invoice_id')
            ->where('s.source_type', $expense->getMorphClass())
            ->where('s.source_id', $expense->id)
            ->where('v.status', '!=', Invoice::VOID)
            ->exists();
        if ($expense->invoice_item_id !== null || $onInvoice) {
            throw new BillingException('This expense is on an invoice (or draft) and cannot be changed.');
        }
    }
}
