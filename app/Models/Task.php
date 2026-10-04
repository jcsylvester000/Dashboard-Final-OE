<?php

namespace App\Models;

use App\Models\Concerns\HasLabels;
use App\Models\Concerns\HasMentions;
use App\Models\Concerns\IsLinkable;
use App\Models\Contracts\Mentionable;
use Database\Factories\TaskFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The single work item shared by every department. Cross-department work
 * moves through handoffs (a linked task in the next department) and
 * dependencies (a task cannot be finished while what it waits on is open).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $project_id
 * @property int|null $department_id
 * @property int $status_id
 * @property int|null $parent_id
 * @property int|null $handoff_from_task_id
 * @property string $title
 * @property string|null $description
 * @property string $priority
 * @property int|null $assignee_id
 * @property int|null $reporter_id
 * @property Carbon|null $due_on
 * @property int|null $estimate_minutes
 * @property int $position
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'workspace_id', 'project_id', 'department_id', 'status_id', 'parent_id', 'handoff_from_task_id',
    'title', 'description', 'priority', 'assignee_id', 'reporter_id', 'due_on', 'estimate_minutes', 'position',
])]
class Task extends Model implements Mentionable
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, HasLabels, HasMentions, IsLinkable, SoftDeletes;

    /** priority => label */
    public const PRIORITIES = [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'completed_at' => 'datetime',
            'estimate_minutes' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
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

    /** @return BelongsTo<TaskStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(TaskStatus::class, 'status_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    /** @return BelongsTo<Task, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_id');
    }

    /** @return HasMany<Task, $this> */
    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_id')->orderBy('position');
    }

    /** @return BelongsTo<Task, $this> */
    public function handoffFrom(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'handoff_from_task_id');
    }

    /**
     * Tasks this one is waiting on ("blocked by").
     *
     * @return BelongsToMany<Task, $this>
     */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'task_id', 'depends_on_task_id')
            ->withPivot(['id', 'type'])
            ->withTimestamps();
    }

    /**
     * Tasks waiting on this one ("blocks").
     *
     * @return BelongsToMany<Task, $this>
     */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'task_dependencies', 'depends_on_task_id', 'task_id')
            ->withPivot(['id', 'type'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<User, $this> */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'task_watchers');
    }

    /** @return MorphMany<Comment, $this> */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    public function mentionWorkspaceId(): int
    {
        return $this->workspace_id;
    }

    public function linkLabel(): string
    {
        return $this->title;
    }

    /**
     * Open tasks this task still waits on.
     */
    public function openDependencyCount(): int
    {
        $doneIds = TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id')->all();

        return $this->dependencies()->whereNotIn('tasks.status_id', $doneIds)->count();
    }
}
