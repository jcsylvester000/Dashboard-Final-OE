<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * E-34: default reviewers for work sent to review in one client, optionally narrowed to a
 * project, a deliverable type (Marketing) and a task type. The most specific match wins.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $project_id
 * @property string|null $deliverable_type
 * @property string|null $task_type
 * @property list<int> $user_ids
 * @property string $mode all | any | round_robin
 * @property int $next_index
 * @property int|null $created_by
 */
#[Fillable(['workspace_id', 'project_id', 'deliverable_type', 'task_type', 'user_ids', 'mode', 'created_by'])]
class ReviewRule extends Model
{
    public const MODES = ['all' => 'Everyone approves', 'any' => 'Any one approves', 'round_robin' => 'Take turns (one reviewer each time)'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['user_ids' => 'array', 'next_index' => 'integer'];
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** How many conditions the rule sets (a more specific rule beats a general one). */
    public function specificity(): int
    {
        return ($this->project_id !== null ? 4 : 0) + ($this->deliverable_type !== null ? 2 : 0) + ($this->task_type !== null ? 1 : 0);
    }
}
