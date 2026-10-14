<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-103: how many PHP one unit of a currency was worth on a day (entered by Finance).
 *
 * @property int $id
 * @property string $currency
 * @property Carbon $rate_on
 * @property string $rate
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['currency', 'rate_on', 'rate', 'note', 'created_by'])]
class ExchangeRate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['rate_on' => 'date', 'rate' => 'decimal:6'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
