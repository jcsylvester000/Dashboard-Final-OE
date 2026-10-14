<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * E-31: a research finding kept for reuse: from a comment, a PDF highlight or a pin,
 * or written by hand. Tagged, rated by confidence, linked to the tasks it informs.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $title
 * @property string|null $body
 * @property list<string> $tags
 * @property string $confidence low | medium | high
 * @property string $source_type comment | annotation | manual
 * @property int|null $source_id
 * @property int|null $task_id
 * @property int|null $attachment_id
 * @property string|null $quote
 * @property int|null $page
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'title', 'body', 'tags', 'confidence', 'source_type', 'source_id', 'task_id', 'attachment_id', 'quote', 'page', 'created_by'])]
class Finding extends Model
{
    public const CONFIDENCE = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tags' => 'array', 'page' => 'integer'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Task, $this> the task the source sits on */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<Task, $this> tasks and decisions this finding informs */
    public function linkedTasks(): BelongsToMany
    {
        return $this->belongsToMany(Task::class, 'finding_task');
    }
}
