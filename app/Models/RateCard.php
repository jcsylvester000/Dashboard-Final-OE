<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Hourly rate. Null workspace = agency-wide; null department and person = default.
 *
 * @property int $id
 * @property int|null $workspace_id
 * @property int|null $department_id
 * @property int|null $user_id
 * @property int $rate_minor
 * @property int|null $cost_minor
 * @property Carbon $effective_from
 */
#[Fillable(['workspace_id', 'department_id', 'user_id', 'rate_minor', 'cost_minor', 'effective_from'])]
class RateCard extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'workspace_id' => 'integer',
            'department_id' => 'integer',
            'user_id' => 'integer',
            'rate_minor' => 'integer',
            'cost_minor' => 'integer',
            'effective_from' => 'date',
        ];
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
