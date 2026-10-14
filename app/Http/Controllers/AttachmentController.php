<?php

namespace App\Http\Controllers;

use App\Domain\Files\AttachmentPresenter;
use App\Domain\Files\AttachmentService;
use App\Domain\Files\FileStore;
use App\Domain\Files\UploadThing\UploadThingClient;
use App\Domain\Files\UploadThing\UploadThingException;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Work\CommentService;
use App\Domain\Work\TaskService;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\DriveFile;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Upload, download (signed, expiring links only) and delete attachments.
 */
class AttachmentController extends Controller
{
    public const TYPES = ['task' => Task::class, 'project' => Project::class, 'comment' => Comment::class, 'workspace' => Workspace::class];

    public function __construct(private readonly AttachmentService $files) {}

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'attachable_type' => ['required', Rule::in(array_keys(self::TYPES))],
            'attachable_id' => ['required', 'integer'],
            // E-02: upload as a new version of this file.
            'version_of' => ['nullable', 'integer', Rule::exists('attachments', 'id')->whereNull('deleted_at')],
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
        $versionOf = isset($data['version_of']) ? Attachment::query()->findOrFail((int) $data['version_of']) : null;
        if ($versionOf !== null && ($versionOf->attachable_type !== $model->getMorphClass() || $versionOf->attachable_id !== $model->getKey())) {
            throw ValidationException::withMessages(['version_of' => __('That file belongs to something else.')]);
        }
        $attachment = $this->files->attach($model, $workspaceId, $file, $user, $versionOf);

        // E-10: pasted screenshots upload in the background and come back as a token for the comment.
        if ($request->expectsJson()) {
            return response()->json(['id' => $attachment->id, 'name' => $attachment->original_name, 'token' => '[[file:'.$attachment->id.']]']);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $attachment->version > 1
            ? __('Uploaded :name as version :v.', ['name' => $attachment->original_name, 'v' => $attachment->version])
            : __('Uploaded :name.', ['name' => $attachment->original_name])]);

        return back();
    }

    /**
     * GET /files/{attachment}?download=1&thumb=1 - signed URL + signed-in member who can open the workspace.
     */
    public function show(Request $request, Attachment $attachment, WorkspaceAccess $access, FileStore $store, UploadThingClient $uploadThing): StreamedResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::query()->find($attachment->workspace_id);
        abort_unless($workspace !== null && $access->canView($user, $workspace), 403);

        // Thumbnails always live on the local disk, whatever stored the original.
        if ($request->boolean('thumb') && $attachment->thumbnail_key !== null) {
            return $store->response($attachment->thumbnail_key, 'thumb.webp', 'image/webp', true);
        }

        // On UploadThing (uploaded there, or linked from My files): hand out a short-lived link.
        if ($attachment->disk === 'uploadthing') {
            try {
                return redirect()->away($uploadThing->fileUrl($attachment->key, (int) config('files.drive.link_seconds', 300)));
            } catch (UploadThingException $e) {
                abort(502, $e->getMessage());
            }
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
     * E-06: one decision per version. "changes" needs a note, clears an approval and, when
     * the task was In Review, sends it back with the note for the assignee.
     */
    public function review(Request $request, Attachment $attachment, AttachmentPresenter $presenter, ActivityLogger $activity, TaskService $tasks, CommentService $comments): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::query()->find($attachment->workspace_id);
        $record = $attachment->attachable;
        abort_unless($workspace !== null && $record !== null && $presenter->canApprove($record, $workspace, $user), 403);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'changes', 'clear'])],
            'note' => ['nullable', 'required_if:decision,changes', 'string', 'max:2000'],
        ]);
        if (! $attachment->is_latest && $data['decision'] !== 'clear') {
            throw ValidationException::withMessages(['decision' => __('Only the latest version can be approved or sent back.')]);
        }

        $approve = $data['decision'] === 'approve';
        $attachment->forceFill([
            'approved_at' => $approve ? now() : null,
            'approved_by' => $approve ? $user->id : null,
            'review_status' => $data['decision'] === 'clear' ? null : ($approve ? 'approved' : 'changes'),
            'review_note' => $data['decision'] === 'changes' ? trim((string) $data['note']) : null,
        ])->save();

        $label = $attachment->original_name.' v'.$attachment->version;
        $activity->log(match ($data['decision']) {
            'approve' => 'file.approved', 'changes' => 'file.changes-requested', default => 'file.unapproved'
        },
            $record instanceof Task ? $record : $attachment, ['file' => $label, 'note' => $attachment->review_note], $user);

        if ($data['decision'] === 'changes' && $record instanceof Task) {
            $assignee = $record->assignee;
            $tag = $assignee !== null && $assignee->id !== $user->id ? '@['.$assignee->name.'](user:'.$assignee->id.') ' : '';
            $comments->create($record, $tag.__('Changes requested on :file: :note', ['file' => $label, 'note' => $attachment->review_note]), $user);
            if ($record->status_id === TaskStatus::idFor('in-review')) {
                $tasks->update($record, ['status_id' => TaskStatus::idFor('in-progress')], $user);
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($data['decision']) {
            'approve' => __(':file approved.', ['file' => $label]),
            'changes' => __('Changes requested on :file.', ['file' => $label]),
            default => __('Decision cleared.'),
        }]);

        return back();
    }

    /**
     * Mark a file approved (or undo). Logged on the task timeline.
     */
    public function approve(Request $request, Attachment $attachment, AttachmentPresenter $presenter, ActivityLogger $activity): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::query()->find($attachment->workspace_id);
        $record = $attachment->attachable;
        abort_unless($workspace !== null && $record !== null && $presenter->canApprove($record, $workspace, $user), 403);

        $approve = $request->boolean('approved', true);
        $attachment->forceFill([
            'approved_at' => $approve ? now() : null,
            'approved_by' => $approve ? $user->id : null,
            'review_status' => $approve ? 'approved' : null,
            'review_note' => null,
        ])->save();

        $activity->log($approve ? 'file.approved' : 'file.unapproved', $record instanceof Task ? $record : $attachment, ['file' => $attachment->original_name], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $approve ? __('File approved.') : __('Approval removed.')]);

        return back();
    }

    /**
     * "Attach from My files": link one of my drive files to a task or project
     * (no copy; the drive file can't be deleted while it is attached).
     */
    public function fromDrive(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'attachable_type' => ['required', Rule::in(['task', 'project'])],
            'attachable_id' => ['required', 'integer'],
            'drive_file_id' => ['required', 'integer'],
        ]);

        $drive = DriveFile::query()->ready()->findOrFail((int) $data['drive_file_id']);
        abort_unless(Gate::allows('view', $drive), 403);
        [$model, $workspaceId] = $this->resolveForUpload((string) $data['attachable_type'], (int) $data['attachable_id']);

        $exists = Attachment::query()->where('attachable_type', $model->getMorphClass())->where('attachable_id', $model->getKey())
            ->where('drive_file_id', $drive->id)->exists();
        if (! $exists) {
            $attachment = Attachment::create([
                'workspace_id' => $workspaceId,
                'attachable_type' => $model->getMorphClass(),
                'attachable_id' => $model->getKey(),
                'uploaded_by' => $user->id,
                'disk' => 'uploadthing',
                'key' => (string) $drive->storage_key,
                'original_name' => $drive->name,
                'mime' => $drive->mime,
                'size' => $drive->size,
                'drive_file_id' => $drive->id,
            ]);
            $activity = app(ActivityLogger::class);
            $activity->log('file.attached', $model instanceof Task ? $model : $attachment, ['file' => $drive->name, 'from' => 'drive'], $user);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Attached :name from My files.', ['name' => $drive->name])]);

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
