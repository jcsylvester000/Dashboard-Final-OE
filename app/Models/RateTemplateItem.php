<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of a rate template: a person's rate, or a department fallback (user_id null).
 *
 * @property int $id
 * @property int $rate_template_id
 * @property int|null $user_id
 * @property int|null $department_id
 * @property int $rate_minor
 * @property int|null $cost_minor
 */
#[Fillable(['rate_template_id', 'user_id', 'department_id', 'rate_minor', 'cost_minor'])]
class RateTemplateItem extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'department_id' => 'integer', 'rate_minor' => 'integer', 'cost_minor' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
