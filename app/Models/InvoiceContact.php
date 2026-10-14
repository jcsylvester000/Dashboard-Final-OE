<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-72: one collection follow-up on an overdue invoice (call, chat, meeting) and any
 * promise-to-pay date the client gave.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int|null $user_id
 * @property string $note
 * @property Carbon|null $promise_to_pay_on
 * @property Carbon|null $created_at
 */
#[Fillable(['invoice_id', 'user_id', 'note', 'promise_to_pay_on'])]
class InvoiceContact extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['promise_to_pay_on' => 'date', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
