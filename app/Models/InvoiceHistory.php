<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only status history of an invoice (who / when / what).
 *
 * @property int $id
 * @property int $invoice_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $note
 * @property int|null $user_id
 * @property Carbon $created_at
 */
#[Fillable(['invoice_id', 'from_status', 'to_status', 'note', 'user_id'])]
class InvoiceHistory extends Model
{
    protected $table = 'invoice_history';

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Invoice history is append-only.'));
        static::deleting(fn () => throw new LogicException('Invoice history is append-only.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
