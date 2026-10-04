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
 */
#[Fillable(['invoice_id', 'type', 'description', 'department_id', 'minutes', 'quantity', 'unit_minor', 'amount_minor', 'position'])]
class InvoiceItem extends Model
{
    public const TYPES = ['retainer', 'hours', 'fixed', 'expense', 'credit', 'adjustment'];

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
