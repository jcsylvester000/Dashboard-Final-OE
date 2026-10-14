<?php

namespace App\Models;

use App\Domain\Billing\BillingException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One invoice line. Lines of a finalized invoice cannot be changed or removed.
 *
 * @property int $id
 * @property int $invoice_id
 * @property string $type
 * @property string $description
 * @property int|null $department_id
 * @property int|null $minutes
 * @property string $quantity
 * @property int $unit_minor
 * @property int $amount_minor
 * @property int $position
 * @property bool $manual added by Finance on the draft (E-63); kept when the draft is rebuilt
 * @property bool $taxable
 * @property int|null $discount_bp percentage discount (basis points); the amount recomputes from it
 * @property int|null $applies_to_item_id a line discount's target line (null = whole invoice)
 * @property string|null $reason
 * @property int|null $prepaid_hours_id E-74: the hour balance this line draws on
 */
#[Fillable(['invoice_id', 'type', 'description', 'department_id', 'minutes', 'quantity', 'unit_minor', 'amount_minor', 'position', 'manual', 'taxable', 'discount_bp', 'applies_to_item_id', 'reason', 'prepaid_hours_id'])]
class InvoiceItem extends Model
{
    public const TYPES = ['retainer', 'prepaid', 'hours', 'fixed', 'expense', 'credit', 'adjustment', 'discount'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['manual' => false, 'taxable' => true];

    protected static function booted(): void
    {
        $guard = function (InvoiceItem $item) {
            $status = Invoice::query()->whereKey($item->invoice_id)->value('status');
            if ($status !== null && $status !== Invoice::DRAFT) {
                throw new BillingException('Lines of a finalized invoice cannot be changed.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'quantity' => 'decimal:2',
            'unit_minor' => 'integer',
            'amount_minor' => 'integer',
            'position' => 'integer',
            'manual' => 'boolean',
            'taxable' => 'boolean',
            'discount_bp' => 'integer',
            'applies_to_item_id' => 'integer',
        ];
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasMany<InvoiceItemSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(InvoiceItemSource::class);
    }
}
