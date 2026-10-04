<?php

namespace App\Http\Controllers;

use App\Domain\Files\AttachmentService;
use App\Domain\Files\FileStore;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Upload, download (signed, expiring links only) and delete attachments.
 */
class AttachmentController extends Controller
{
    public const TYPES = ['task' => Task::class, 'project' => Project::class, 'comment' => Comment::class, 'workspace' => Workspace::class];

    public function __construct(private readonly AttachmentService $files) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'attachable_type' => ['required', Rule::in(array_keys(self::TYPES))],
            'attachable_id' => ['required', 'integer'],
            'file' => $this->files->rules(),
        ], [
            'file.mimes' => __('That file type is not allowed. Allowed: :types.', ['types' => implode(', ', $this->files->allowedExtensions())]),
            'file.max' => __('Files can be at most :mb MB.', ['mb' => $this->files->maxMb()]),
        ]);

        [$model, $workspaceId] = $this->resolveForUpload((string) $data['attachable_type'], (int) $data['attachable_id']);

        /** @var User $user */
        $user = $request->user();
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $attachment = $this->files->attach($model, $workspaceId, $file, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Uploaded :name.', ['name' => $attachment->original_name])]);

        return back();
    }

    /**
     * GET /files/{attachment}?download=1&thumb=1 - signed URL + signed-in member who can open the workspace.
     */
    public function show(Request $request, Attachment $attachment, WorkspaceAccess $access, FileStore $store): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::query()->find($attachment->workspace_id);
        abort_unless($workspace !== null && $access->canView($user, $workspace), 403);

        if ($request->boolean('thumb') && $attachment->thumbnail_key !== null) {
            return $store->response($attachment->thumbnail_key, 'thumb.webp', 'image/webp', true);
        }

        abort_unless($store->exists($attachment->key), 404);

        return $store->response(
            $attachment->key,
            $attachment->original_name,
            $attachment->mime,
            ! $request->boolean('download') && $attachment->previewable(),
        );
    }

    public function destroy(Request $request, Attachment $attachment): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::query()->find($attachment->workspace_id);
        abort_unless(
            $workspace !== null && ($attachment->uploaded_by === $user->id ? Gate::allows('contribute', $workspace) : Gate::allows('lead', $workspace)),
            403,
        );

        $this->files->delete($attachment, $user);

        return back();
    }

    /**
     * The record to attach to, after checking the user may change it.
     *
     * @return array{0: Model, 1: int}
     */
    private function resolveForUpload(string $type, int $id): array
    {
        return match ($type) {
            'task' => $this->task(Task::query()->findOrFail($id)),
            'comment' => $this->comment(Comment::query()->findOrFail($id)),
            'project' => $this->project(Project::query()->findOrFail($id)),
            default => $this->workspace(Workspace::query()->findOrFail($id)),
        };
    }

    /**
     * @return array{0: Model, 1: int}
     */
    private function task(Task $task): array
    {
        Gate::authorize('update', $task);

        return [$task, $task->workspace_id];
    }

    /**
     * @return array{0: Model, 1: int}
     */
    private function comment(Comment $comment): array
    {
        $comment->loadMissing('commentable');
        $task = $comment->commentable;
        abort_unless($task instanceof Task, 404);
        Gate::authorize('update', $task);

        return [$comment, $comment->workspace_id];
    }

    /**
     * @return array{0: Model, 1: int}
     */
    private function project(Project $project): array
    {
        Gate::authorize('update', $project);

        return [$project, $project->workspace_id];
    }

    /**
     * @return array{0: Model, 1: int}
     */
    private function workspace(Workspace $workspace): array
    {
        Gate::authorize('contribute', $workspace);

        return [$workspace, $workspace->id];
    }
}
