<?php

namespace App\Models\Contracts;

/**
 * A record whose text can @mention workspace members (see MentionService).
 */
interface Mentionable
{
    /** Workspace the mentions belong to (scopes who may be tagged). */
    public function mentionWorkspaceId(): int;
}
