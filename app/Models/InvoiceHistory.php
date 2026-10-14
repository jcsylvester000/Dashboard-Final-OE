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
 * @property string|null $user_name E-86: the person's name as it was (survives anonymisation)
 * @property Carbon $created_at
 */
#[Fillable(['invoice_id', 'from_status', 'to_status', 'note', 'user_id', 'user_name'])]
class InvoiceHistory extends Model
{
    protected $table = 'invoice_history';

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        // E-86: billing records keep the name as written, whatever happens to the account later.
        static::creating(function (InvoiceHistory $h) {
            if ($h->user_id !== null && $h->user_name === null) {
                $h->user_name = User::query()->whereKey($h->user_id)->value('name');
            }
        });
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
