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
 * @property array<string, string|null>|null $work_details
 * @property string $priority
 * @property int|null $assignee_id
 * @property int|null $reporter_id
 * @property Carbon|null $due_on
 * @property Carbon|null $start_on
 * @property string|null $recurrence weekly | biweekly | monthly
 * @property Carbon|null $recurrence_until
 * @property int|null $recurred_from_task_id
 * @property int|null $estimate_minutes
 * @property int $position
 * @property int|null $number per-client running number (BRW-24)
 * @property string $type task | story | bug | chore
 * @property int|null $sprint_id
 * @property int|null $story_points
 * @property Carbon|null $completed_at
 * @property string $review_rule all | any (E-07)
 * @property Carbon|null $review_requested_at when the task last entered In Review (E-33)
 * @property array<string, string|null>|null $bug_report steps, expected, actual, environment, severity (E-21)
 * @property array<string, mixed>|null $custom_values B-07: custom field key => value
 * @property int|null $delivery_spec_id E-18: video spec chosen for this task (else matched by channel)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'workspace_id', 'project_id', 'department_id', 'status_id', 'parent_id', 'handoff_from_task_id',
    'title', 'description', 'work_details', 'priority', 'assignee_id', 'reporter_id', 'due_on', 'estimate_minutes', 'position', 'type', 'sprint_id', 'story_points', 'start_on', 'recurrence', 'recurrence_until', 'custom_values', 'bug_report',
])]
class Task extends Model implements Mentionable
{
    /** @use HasFactory<TaskFactory> */
    use HasFactory, HasLabels, HasMentions, IsLinkable, SoftDeletes;

    /**
     * Department-specific fields stored in work_details, by department slug.
     * field => [label, input type, options?]
     *
     * @var array<string, array<string, array{0: string, 1: string, 2?: list<string>}>>
     */
    public const WORK_DETAIL_FIELDS = [
        'marketing' => [
            // S2-01: one task can run on several channels (stored as "Facebook, Instagram").
            'channel' => ['Channels', 'multiselect', ['Facebook', 'Instagram', 'TikTok', 'Google Ads', 'Email', 'LinkedIn', 'YouTube', 'Website', 'Other']],
            // Blank = the project name (reports fill it in), so it isn't typed twice.
            'campaign' => ['Campaign', 'text'],
            'deliverable_type' => ['Deliverable', 'select', ['Ad creative', 'Copy', 'Post', 'Video', 'Landing page', 'Report', 'Other']],
        ],
        'seo' => [
            'target_url' => ['Target URL', 'url'],
            'keyword' => ['Primary keyword', 'text'],
            'work_type' => ['Work type', 'select', ['On-page', 'Technical', 'Content', 'Links', 'Local', 'Reporting']],
        ],
        // E-28: research tasks.
        'research' => [
            'question' => ['Research question', 'text'],
            'method' => ['Method', 'select', ['Interviews', 'Survey', 'Desk research', 'Analytics', 'Usability test', 'Other']],
            'sources' => ['Sources', 'text'],
            'confidence' => ['Confidence', 'select', ['Low', 'Medium', 'High']],
            'decision' => ['Decision it informs', 'text'],
        ],
    ];

    /** E-21: bug severity => label */
    public const SEVERITIES = ['critical' => 'Critical (blocks work)', 'high' => 'High', 'medium' => 'Medium', 'low' => 'Low'];

    /** type => label (Jira-style issue types) */
    public const TYPES = [
        'task' => 'Task',
        'story' => 'Story',
        'bug' => 'Bug',
        'chore' => 'Chore',
        'milestone' => 'Milestone',
    ];

    protected static function booted(): void
    {
        static::creating(function (Task $task) {
            $task->number ??= Workspace::claimTaskNumber($task->workspace_id);
        });
    }

    /**
     * "BRW-24" when the client has a key (needs workspace loaded with `key`), else "#id".
     */
    public function keyLabel(): string
    {
        $key = $this->relationLoaded('workspace') ? $this->workspace?->key : null;

        return $key !== null && $this->number !== null ? $key.'-'.$this->number : '#'.$this->id;
    }

    /**
     * Find a task by "BRW-24" (case-insensitive). Returns null for anything else.
     */
    public static function findByKey(string $key): ?self
    {
        if (! preg_match('/^([A-Za-z][A-Za-z0-9]{1,5})-(\d{1,9})$/', trim($key), $m)) {
            return null;
        }

        return self::query()
            ->whereHas('workspace', fn ($q) => $q->where('key', strtoupper($m[1])))
            ->where('number', (int) $m[2])
            ->first();
    }

    /** recurrence => label (B-04) */
    public const RECURRENCES = [
        'weekly' => 'Every week',
        'biweekly' => 'Every 2 weeks',
        'monthly' => 'Every month',
    ];

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
            'start_on' => 'date',
            'recurrence_until' => 'date',
            'completed_at' => 'datetime',
            'work_details' => 'array',
            'custom_values' => 'array',
            'bug_report' => 'array',
            'review_requested_at' => 'datetime',
            'estimate_minutes' => 'integer',
            'position' => 'integer',
            'number' => 'integer',
            'story_points' => 'integer',
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

    /** @return BelongsTo<Sprint, $this> */
    public function sprint(): BelongsTo
    {
        return $this->belongsTo(Sprint::class);
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

    /**
     * E-29: sources captured on the task.
     *
     * @return HasMany<TaskCitation, $this>
     */
    public function citations(): HasMany
    {
        return $this->hasMany(TaskCitation::class)->orderBy('id');
    }

    /** @return HasMany<TimeEntry, $this> */
    public function timeEntries(): HasMany
    {
        return $this->hasMany(TimeEntry::class);
    }

    /** @return HasMany<TaskReviewer, $this> */
    public function reviewers(): HasMany
    {
        return $this->hasMany(TaskReviewer::class);
    }

    /** @return HasMany<TaskChecklistItem, $this> */
    public function checklistItems(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class)->orderBy('position')->orderBy('id');
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
