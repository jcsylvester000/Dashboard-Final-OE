<?php

namespace App\Models;

use App\Models\Concerns\HasMentions;
use App\Models\Contracts\Mentionable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $workspace_id
 * @property string $commentable_type
 * @property int $commentable_id
 * @property int|null $user_id
 * @property string $body
 * @property Carbon|null $edited_at
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'commentable_type', 'commentable_id', 'user_id', 'body'])]
class Comment extends Model implements Mentionable
{
    use HasMentions, SoftDeletes;

    /** Minutes during which the author may edit a comment. */
    public const EDIT_WINDOW_MINUTES = 15;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return MorphTo<Model, $this> */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function mentionWorkspaceId(): int
    {
        return $this->workspace_id;
    }

    public function editableBy(User $user): bool
    {
        return $this->user_id === $user->id
            && $this->created_at->gt(now()->subMinutes(self::EDIT_WINDOW_MINUTES));
    }
}
