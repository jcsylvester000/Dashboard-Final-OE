<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-24: a pull request, branch or commit that names the task.
 *
 * @property int $id
 * @property int $task_id
 * @property string $repository
 * @property string $kind pr | branch | commit
 * @property string $ref
 * @property int|null $number
 * @property string|null $head_sha
 * @property string|null $title
 * @property string $url
 * @property string|null $state open | draft | merged | closed
 * @property string|null $checks success | failure | pending
 * @property list<string>|null $reviewers
 * @property string|null $author
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['task_id', 'repository', 'kind', 'ref', 'number', 'head_sha', 'title', 'url', 'state', 'checks', 'reviewers', 'author'])]
class TaskCodeLink extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['reviewers' => 'array', 'number' => 'integer'];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }
}
