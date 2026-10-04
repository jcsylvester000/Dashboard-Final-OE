<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Work\CommentService;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CommentResource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CommentController extends Controller
{
    public function index(Task $task): AnonymousResourceCollection
    {
        Gate::authorize('view', $task);

        return CommentResource::collection($task->comments()->with('author:id,name')->oldest()->paginate(100));
    }

    public function store(Request $request, Task $task, CommentService $comments): JsonResponse
    {
        Gate::authorize('update', $task);
        $body = (string) $request->validate(['body' => ['required', 'string', 'max:10000']])['body'];

        /** @var User $user */
        $user = $request->user();
        $comment = $comments->create($task, $body, $user);

        return (new CommentResource($comment->load('author:id,name')))->response()->setStatusCode(201);
    }
}
