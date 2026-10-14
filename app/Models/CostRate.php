<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-77: a person's internal cost per hour (PHP), from a date. Finance only.
 *
 * @property int $id
 * @property int $user_id
 * @property int $cost_minor
 * @property Carbon $effective_from
 * @property string|null $note
 * @property int|null $created_by
 */
#[Fillable(['user_id', 'cost_minor', 'effective_from', 'note', 'created_by'])]
class CostRate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['cost_minor' => 'integer', 'effective_from' => 'date'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
