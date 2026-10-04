<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Client payment, recorded manually by Finance.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $amount_minor
 * @property string $method
 * @property string|null $reference
 * @property Carbon $received_on
 * @property string|null $note
 * @property int|null $recorded_by
 */
#[Fillable(['invoice_id', 'amount_minor', 'method', 'reference', 'received_on', 'note', 'recorded_by'])]
class Payment extends Model
{
    public const METHODS = [
        'bank' => 'Bank transfer',
        'gcash' => 'GCash',
        'maya' => 'Maya',
        'card' => 'Card',
        'check' => 'Check',
        'cash' => 'Cash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'received_on' => 'date'];
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
