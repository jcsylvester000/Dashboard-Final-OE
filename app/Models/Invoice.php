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
 * @property int|null $project_id one project/campaign only; null = whole client
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
 * @property string $line_grouping
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
 * @property list<array{user_id: int|null, name: string, department_id: int|null, minutes: int, first: string, last: string}>|null $missing_rates work the draft could not price (E-64)
 * @property Carbon|null $created_at
 * @property string|null $service_label shown instead of the period dates when set (E-67)
 * @property Carbon|null $review_requested_at draft sent for a second person's check (E-66)
 * @property int|null $review_requested_by
 * @property string|null $review_note last send-back note
 * @property string|null $share_token client link (E-70)
 * @property Carbon|null $share_expires_at
 * @property Carbon|null $share_first_viewed_at
 * @property int $share_views
 * @property int $collection_stage overdue follow-up stage reached (E-72): 0 none, 1 = 7 days, 2 = 14, 3 = 30
 * @property Carbon|null $promise_to_pay_on alerts pause until this date passes
 * @property Carbon|null $last_contact_at
 * @property string|null $php_rate PHP per 1 unit, locked at finalize (E-104)
 * @property Carbon|null $php_rate_on the day of the rate used
 */
#[Fillable(['workspace_id', 'billing_period_id', 'project_id', 'kind', 'credited_invoice_id', 'series', 'currency', 'period_start', 'period_end', 'tax_rate_bp', 'line_grouping', 'bill_to', 'notes', 'created_by', 'service_label'])]
class Invoice extends Model
{
    /** How hour lines are grouped: department, project or person. */
    public const GROUPINGS = ['department' => 'Department', 'project' => 'Project / campaign', 'person' => 'Team member'];

    public const DRAFT = 'draft';

    public const FINALIZED = 'finalized';

    public const PARTIALLY_PAID = 'partially_paid';

    public const PAID = 'paid';

    public const VOID = 'void';

    /** Columns that may still change after finalize. */
    private const MUTABLE_AFTER_FINALIZE = ['status', 'paid_minor', 'credited_minor', 'pdf_path', 'overdue_alerted_at', 'updated_at',
        // E-70 / E-72: client link and collection follow-up are not part of the document.
        'share_token', 'share_expires_at', 'share_first_viewed_at', 'share_views', 'collection_stage', 'promise_to_pay_on', 'last_contact_at',
        // E-104: set once (at finalize, or by "Lock rate" when no rate existed yet).
        'php_rate', 'php_rate_on'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => self::DRAFT, 'kind' => 'invoice', 'paid_minor' => 0, 'credited_minor' => 0, 'share_views' => 0, 'collection_stage' => 0];

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
            'missing_rates' => 'array',
            'finalized_at' => 'datetime',
            'overdue_alerted_at' => 'datetime',
            'review_requested_at' => 'datetime',
            'review_requested_by' => 'integer',
            'share_expires_at' => 'datetime',
            'share_first_viewed_at' => 'datetime',
            'share_views' => 'integer',
            'collection_stage' => 'integer',
            'promise_to_pay_on' => 'date',
            'last_contact_at' => 'datetime',
            'php_rate' => 'decimal:6',
            'php_rate_on' => 'date',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
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

    /** @return HasMany<InvoiceContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(InvoiceContact::class)->orderByDesc('id');
    }

    /** Days past due today (0 when not yet due or no due date). */
    public function daysOverdue(): int
    {
        if ($this->due_date === null) {
            return 0;
        }

        return max(0, (int) $this->due_date->copy()->startOfDay()->diffInDays(now()->startOfDay(), false));
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
