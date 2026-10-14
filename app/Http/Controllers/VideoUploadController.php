<?php

namespace App\Http\Controllers;

use App\Domain\Files\Video\LocalVideoStorage;
use App\Domain\Files\Video\VideoStorage;
use App\Domain\Files\Video\VideoUploads;
use App\Domain\Work\TaskService;
use App\Models\Attachment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * E-11: direct, resumable video uploads (JSON). The browser asks for part URLs,
 * PUTs each part straight to storage, then completes. Only the uploader can
 * continue, complete or cancel an upload.
 */
class VideoUploadController extends Controller
{
    public function __construct(private readonly VideoUploads $uploads) {}

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'attachable_type' => ['required', Rule::in(['task', 'project'])],
            'attachable_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'version_of' => ['nullable', 'integer'],
        ]);
        $record = $data['attachable_type'] === 'task'
            ? Task::query()->findOrFail((int) $data['attachable_id'])
            : Project::query()->findOrFail((int) $data['attachable_id']);
        Gate::authorize('update', $record);

        $versionOf = null;
        if (isset($data['version_of'])) {
            $versionOf = Attachment::query()->findOrFail((int) $data['version_of']);
            if ($versionOf->attachable_type !== $record->getMorphClass() || $versionOf->attachable_id !== $record->getKey()) {
                throw ValidationException::withMessages(['version_of' => __('That file belongs to something else.')]);
            }
        }
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->uploads->start($record, $record->workspace_id, $user, (string) $data['name'], (int) $data['size'], $versionOf), 201);
    }

    /** Presigned URLs for a batch of parts (fresh links for every batch). */
    public function parts(Request $request, Attachment $attachment): JsonResponse
    {
        $this->own($request, $attachment);
        $parts = $request->validate(['parts' => ['required', 'array', 'min:1', 'max:50'], 'parts.*' => ['integer', 'min:1']])['parts'];

        return response()->json(['urls' => $this->uploads->partUrls($attachment, array_values(array_map('intval', $parts)))]);
    }

    /** Local driver only: receive one part (signed URL); answers its ETag like S3. */
    public function part(Request $request, Attachment $attachment, int $part, VideoStorage $storage): JsonResponse
    {
        $this->own($request, $attachment);
        abort_unless($storage instanceof LocalVideoStorage && $attachment->upload_state === 'uploading', 404);
        abort_if($part < 1 || $part > (int) ceil($attachment->size / VideoUploads::partBytes()), 422);
        $length = (int) $request->header('Content-Length', '0');
        abort_if($length > VideoUploads::partBytes(), 413);

        $etag = $storage->storePart($attachment, $part, $request->getContent(true));

        return response()->json(['part' => $part], 200, ['ETag' => '"'.$etag.'"', 'Access-Control-Expose-Headers' => 'ETag']);
    }

    public function complete(Request $request, Attachment $attachment, TaskService $tasks): JsonResponse
    {
        $user = $this->own($request, $attachment);
        $data = $request->validate([
            'parts' => ['required', 'array', 'min:1', 'max:10000'],
            'parts.*.part' => ['required', 'integer', 'min:1'],
            'parts.*.etag' => ['required', 'string', 'max:200'],
        ]);
        $parts = array_values(array_map(fn (array $p): array => ['part' => (int) $p['part'], 'etag' => (string) $p['etag']], $data['parts']));
        $done = $this->uploads->complete($attachment, $parts, $user);

        // E-35: a new version answering "changes requested" sends the task back for review.
        $model = $done->attachable;
        if ($done->version_root_id !== null && $model instanceof Task && $model->status_id === TaskStatus::idFor('in-progress')) {
            $previous = Attachment::query()->where(fn ($q) => $q->whereKey($done->version_root_id)->orWhere('version_root_id', $done->version_root_id))
                ->where('id', '!=', $done->id)->orderByDesc('version')->first();
            if ($previous !== null && $previous->review_status === 'changes') {
                $tasks->update($model, ['status_id' => TaskStatus::idFor('in-review')], $user);
            }
        }

        return response()->json(['id' => $done->id, 'name' => $done->original_name, 'version' => $done->version, 'media_status' => $done->media_status]);
    }

    public function abort(Request $request, Attachment $attachment): JsonResponse
    {
        $this->own($request, $attachment);
        $this->uploads->abort($attachment);

        return response()->json(['aborted' => true]);
    }

    private function own(Request $request, Attachment $attachment): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($attachment->uploaded_by === $user->id && $attachment->isVideoAsset(), 404);

        return $user;
    }
}
