<?php

namespace App\Http\Controllers\Work;

use App\Domain\Work\CommentService;
use App\Domain\Workspaces\MentionService;
use App\Http\Controllers\Controller;
use App\Models\Comment;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Task discussion with @mentions. Authors can edit for 15 minutes;
 * authors and workspace leads can delete.
 */
class CommentController extends Controller
{
    public function __construct(
        private readonly MentionService $mentions,
    ) {}

    public function store(Request $request, Workspace $workspace, Task $task, CommentService $comments): RedirectResponse
    {
        Gate::authorize('update', $task);

        $body = (string) $request->validate(['body' => ['required', 'string', 'max:10000']])['body'];
        /** @var User $user */
        $user = $request->user();

        $comments->create($task, $body, $user);

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
