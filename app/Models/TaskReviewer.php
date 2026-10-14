<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-07: a named reviewer on a task and their decision for the current review round.
 *
 * @property int $id
 * @property int $task_id
 * @property int $user_id
 * @property string|null $decision approved | changes
 * @property string|null $note
 * @property Carbon|null $decided_at
 */
#[Fillable(['task_id', 'user_id', 'decision', 'note', 'decided_at'])]
class TaskReviewer extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
