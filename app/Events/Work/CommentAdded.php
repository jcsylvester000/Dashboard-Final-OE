<?php

namespace App\Events\Work;

use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A comment was posted on a task. $mentionedIds already get a mention alert. */
class CommentAdded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<int>  $mentionedIds
     */
    public function __construct(
        public readonly Comment $comment,
        public readonly Task $task,
        public readonly User $actor,
        public readonly array $mentionedIds = [],
    ) {}
}
