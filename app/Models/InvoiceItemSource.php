<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * What an invoice line bills: a time entry, expense or fixed-fee project.
 *
 * @property int $id
 * @property int $invoice_item_id
 * @property string $source_type
 * @property int $source_id
 * @property int|null $minutes
 * @property int|null $amount_minor
 * @property Carbon|null $created_at
 */
#[Fillable(['invoice_item_id', 'source_type', 'source_id', 'minutes', 'amount_minor'])]
class InvoiceItemSource extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['minutes' => 'integer', 'amount_minor' => 'integer'];
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
