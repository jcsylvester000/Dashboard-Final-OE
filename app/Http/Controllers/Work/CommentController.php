<?php

namespace App\Http\Controllers\Work;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Work\TaskService;
use App\Domain\Workspaces\MentionService;
use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Task discussion with @mentions. Authors can edit for 15 minutes;
 * authors and workspace leads can delete.
 */
class CommentController extends Controller
{
    public function __construct(
        private readonly MentionService $mentions,
        private readonly ActivityLogger $activity,
    ) {}

    public function store(Request $request, Workspace $workspace, Task $task, TaskService $tasks): RedirectResponse
    {
        Gate::authorize('update', $task);

        $body = (string) $request->validate(['body' => ['required', 'string', 'max:10000']])['body'];
        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($workspace, $task, $body, $user, $tasks) {
            $normalized = $this->mentions->normalize($body, $workspace->id);

            $comment = Comment::create([
                'workspace_id' => $workspace->id,
                'commentable_type' => $task->getMorphClass(),
                'commentable_id' => $task->id,
                'user_id' => $user->id,
                'body' => (string) $normalized['text'],
            ]);

            $this->mentions->sync($comment, $normalized['user_ids'], $user);
            // Commenting or being tagged means you follow the task from now on.
            $tasks->watch($task, [$user->id, ...$normalized['user_ids']]);

            $this->activity->log('task.commented', $task, ['comment_id' => $comment->id], $user);
        });

        return back();
    }

    public function update(Request $request, Workspace $workspace, Comment $comment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($comment->editableBy($user), 403, 'Comments can only be edited by their author for 15 minutes.');

        $body = (string) $request->validate(['body' => ['required', 'string', 'max:10000']])['body'];
        $normalized = $this->mentions->normalize($body, $workspace->id);

        $comment->forceFill(['body' => (string) $normalized['text'], 'edited_at' => now()])->save();
        $this->mentions->sync($comment, $normalized['user_ids'], $user);

        return back();
    }

    public function destroy(Request $request, Workspace $workspace, Comment $comment): RedirectResponse
    {
        abort_unless(
            $comment->user_id === $request->user()->id || Gate::allows('lead', $workspace),
            403,
        );

        $comment->delete();

        return back();
    }
}
