<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\SprintFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A time-boxed iteration inside a client workspace (one active at a time per client).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $project_id
 * @property string $name
 * @property string|null $goal
 * @property CarbonInterface $starts_on
 * @property CarbonInterface $ends_on
 * @property string $status planned | active | closed
 * @property CarbonInterface|null $started_at
 * @property CarbonInterface|null $closed_at
 * @property int|null $committed_points
 * @property int|null $completed_points
 * @property int|null $carried_over
 * @property int|null $created_by
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['workspace_id', 'project_id', 'name', 'goal', 'starts_on', 'ends_on', 'created_by'])]
class Sprint extends Model
{
    /** @use HasFactory<SprintFactory> */
    use HasFactory;

    public const PLANNED = 'planned';

    public const ACTIVE = 'active';

    public const CLOSED = 'closed';

    /** Story point scale offered in pickers (any 0-100 value is accepted). */
    public const POINTS = [1, 2, 3, 5, 8, 13, 21];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
            'committed_points' => 'integer',
            'completed_points' => 'integer',
            'carried_over' => 'integer',
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

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }
}
