<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * E-74: an hour balance a client can draw on: rolled-over retainer hours or a prepaid block.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $kind rollover | block
 * @property int $minutes
 * @property int $amount_minor
 * @property string $currency
 * @property Carbon $starts_on
 * @property Carbon|null $expires_on
 * @property int|null $source_invoice_id
 * @property int|null $invoice_id
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable(['workspace_id', 'kind', 'minutes', 'amount_minor', 'currency', 'starts_on', 'expires_on', 'source_invoice_id', 'invoice_id', 'note', 'created_by'])]
class PrepaidHours extends Model
{
    protected $table = 'prepaid_hours';

    public const KINDS = ['rollover' => 'Rolled-over retainer hours', 'block' => 'Prepaid hours block'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'minutes' => 'integer',
            'amount_minor' => 'integer',
            'starts_on' => 'date',
            'expires_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * Invoice lines that draw on this balance.
     *
     * @return HasMany<InvoiceItem, $this>
     */
    public function draws(): HasMany
    {
        return $this->hasMany(InvoiceItem::class, 'prepaid_hours_id');
    }

    public function label(): string
    {
        return $this->kind === 'rollover'
            ? 'Rolled over from '.$this->starts_on->copy()->subDay()->format('M Y')
            : ($this->note !== null && $this->note !== '' ? $this->note : 'Prepaid hours block');
    }
}
