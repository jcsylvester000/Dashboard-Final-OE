<?php

namespace App\Domain\Work;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Workspaces\MentionService;
use App\Events\Work\CommentAdded;
use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Posting a comment on a task (web and API share this): mentions, watchers,
 * timeline and the CommentAdded alert.
 */
class CommentService
{
    public function __construct(
        private readonly MentionService $mentions,
        private readonly TaskService $tasks,
        private readonly ActivityLogger $activity,
    ) {}

    public function create(Task $task, string $body, User $author): Comment
    {
        return DB::transaction(function () use ($task, $body, $author) {
            $normalized = $this->mentions->normalize($body, $task->workspace_id);

            $comment = Comment::create([
                'workspace_id' => $task->workspace_id,
                'commentable_type' => $task->getMorphClass(),
                'commentable_id' => $task->id,
                'user_id' => $author->id,
                'body' => (string) $normalized['text'],
            ]);

            $this->mentions->sync($comment, $normalized['user_ids'], $author);
            // Commenting or being tagged means you follow the task from now on.
            $this->tasks->watch($task, [$author->id, ...$normalized['user_ids']]);

            $this->activity->log('task.commented', $task, ['comment_id' => $comment->id], $author);

            CommentAdded::dispatch($comment, $task, $author, $normalized['user_ids']);

            return $comment;
        });
    }
}
