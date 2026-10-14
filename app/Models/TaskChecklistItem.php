<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-36: one checklist line on a task. Required lines block Done until ticked.
 *
 * @property int $id
 * @property int $task_id
 * @property string $label
 * @property bool $required
 * @property int $position
 * @property Carbon|null $done_at
 * @property int|null $done_by
 */
#[Fillable(['task_id', 'label', 'required', 'position', 'done_at', 'done_by'])]
class TaskChecklistItem extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['required' => true, 'position' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['required' => 'boolean', 'done_at' => 'datetime', 'position' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }
}
