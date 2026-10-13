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
 * @property int|null $rate_template_id
 * @property string $currency ISO code; a client's cards use its billing currency (E-100)
 */
#[Fillable(['workspace_id', 'department_id', 'user_id', 'rate_minor', 'cost_minor', 'effective_from', 'rate_template_id', 'currency'])]
class RateCard extends Model
{
    /**
     * Same default as the database column, so new in-memory models know their currency (E-100).
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['currency' => 'PHP'];

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
