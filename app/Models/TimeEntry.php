<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $workspace_id
 * @property int|null $task_id
 * @property int|null $project_id
 * @property int|null $department_id
 * @property Carbon $entry_date
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property int $minutes
 * @property string|null $note
 * @property bool $is_billable
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $locked_at
 * @property Carbon|null $created_at
 */
#[Fillable(['user_id', 'workspace_id', 'task_id', 'project_id', 'department_id', 'entry_date', 'started_at', 'ended_at', 'minutes', 'note', 'is_billable'])]
class TimeEntry extends Model
{
    use SoftDeletes;

    /** Longest single entry (a timer left running overnight is capped here). */
    public const MAX_MINUTES = 12 * 60;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'minutes' => 'integer',
            'is_billable' => 'boolean',
            'approved_at' => 'datetime',
            'locked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isRunning(): bool
    {
        return $this->started_at !== null && $this->ended_at === null;
    }

    /** Approved or billed entries can no longer be edited by their author. */
    public function isLocked(): bool
    {
        return $this->approved_at !== null || $this->locked_at !== null;
    }

    /**
     * @param  Builder<TimeEntry>  $query
     */
    public function scopeRunning(Builder $query): void
    {
        $query->whereNotNull('started_at')->whereNull('ended_at');
    }
}
