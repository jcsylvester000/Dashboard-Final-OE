<?php

namespace App\Models\Concerns;

use App\Models\Mention;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Records whose text can @mention workspace members.
 * The model must expose the workspace the mention belongs to.
 */
trait HasMentions
{
    /**
     * @return MorphMany<Mention, $this>
     */
    public function mentions(): MorphMany
    {
        return $this->morphMany(Mention::class, 'mentionable');
    }

    abstract public function mentionWorkspaceId(): int;
}
