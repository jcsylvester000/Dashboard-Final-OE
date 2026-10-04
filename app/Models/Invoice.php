<?php

namespace App\Models;

use App\Domain\Billing\BillingException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Client invoice or credit note. Once finalized it is immutable: only status,
 * paid amount, PDF path and the overdue-alert stamp may change afterwards.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $billing_period_id
 * @property string $kind
 * @property int|null $credited_invoice_id
 * @property string $series
 * @property int|null $number
 * @property string $status
 * @property string $currency
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon|null $issue_date
 * @property Carbon|null $due_date
 * @property int $subtotal_minor
 * @property int $tax_rate_bp
 * @property int $tax_minor
 * @property int $total_minor
 * @property int $paid_minor
 * @property int $credited_minor
 * @property array<string, string|null>|null $bill_to
 * @property string|null $notes
 * @property string|null $pdf_path
 * @property Carbon|null $finalized_at
 * @property int|null $finalized_by
 * @property Carbon|null $overdue_alerted_at
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable(['workspace_id', 'billing_period_id', 'kind', 'credited_invoice_id', 'series', 'currency', 'period_start', 'period_end', 'tax_rate_bp', 'bill_to', 'notes', 'created_by'])]
class Invoice extends Model
{
    public const DRAFT = 'draft';

    public const FINALIZED = 'finalized';

    public const PARTIALLY_PAID = 'partially_paid';

    public const PAID = 'paid';

    public const VOID = 'void';

    /** Columns that may still change after finalize. */
    private const MUTABLE_AFTER_FINALIZE = ['status', 'paid_minor', 'credited_minor', 'pdf_path', 'overdue_alerted_at', 'updated_at'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => self::DRAFT, 'kind' => 'invoice', 'paid_minor' => 0, 'credited_minor' => 0];

    protected static function booted(): void
    {
        static::updating(function (Invoice $invoice) {
            if ($invoice->getOriginal('status') === self::DRAFT) {
                return;
            }
            $changed = array_diff(array_keys($invoice->getDirty()), self::MUTABLE_AFTER_FINALIZE);
            if ($changed !== []) {
                throw new BillingException('A finalized invoice cannot be changed. Issue a credit note instead.');
            }
        });

        static::deleting(function (Invoice $invoice) {
            if ($invoice->status !== self::DRAFT) {
                throw new BillingException('Only draft invoices can be deleted.');
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'issue_date' => 'date',
            'due_date' => 'date',
            'subtotal_minor' => 'integer',
            'tax_rate_bp' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'credited_minor' => 'integer',
            'bill_to' => 'array',
            'finalized_at' => 'datetime',
            'overdue_alerted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<BillingPeriod, $this> */
    public function period(): BelongsTo
    {
        return $this->belongsTo(BillingPeriod::class, 'billing_period_id');
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<InvoiceHistory, $this> */
    public function history(): HasMany
    {
        return $this->hasMany(InvoiceHistory::class)->orderBy('id');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->orderBy('received_on')->orderBy('id');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function balanceMinor(): int
    {
        return $this->total_minor - $this->paid_minor - $this->credited_minor;
    }

    /** "INV-0007", or "Draft #12" before a number is assigned. */
    public function displayNumber(): string
    {
        return $this->number !== null
            ? sprintf('%s-%04d', $this->series, $this->number)
            : 'Draft #'.$this->id;
    }
}
