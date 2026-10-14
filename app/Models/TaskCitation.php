<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-29: a source captured on a task (research, SEO, marketing): the link, its page title
 * and site when it could be read, and when it was read.
 *
 * @property int $id
 * @property int $task_id
 * @property string $url
 * @property string|null $title
 * @property string|null $site
 * @property Carbon $accessed_at
 * @property int|null $created_by
 */
#[Fillable(['task_id', 'url', 'title', 'site', 'accessed_at', 'created_by'])]
class TaskCitation extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['accessed_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** A Wayback Machine snapshot link for when the page changes or disappears. */
    public function archiveUrl(): string
    {
        return 'https://web.archive.org/web/'.$this->accessed_at->format('YmdHis').'/'.$this->url;
    }
}
