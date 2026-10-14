<?php

namespace App\Http\Controllers;

use App\Domain\Files\AttachmentPresenter;
use App\Domain\Files\FileStore;
use App\Domain\Files\Video\Captions;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\Attachment;
use App\Models\AttachmentCaption;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

/**
 * E-19: caption tracks (.vtt or .srt, stored as WebVTT) on a video version (JSON).
 * Anyone who can open the client can watch them; members and reviewers add or remove.
 */
class CaptionController extends Controller
{
    public const MAX_PER_VIDEO = 10;

    public function __construct(
        private readonly WorkspaceAccess $access,
        private readonly FileStore $store,
        private readonly ActivityLogger $activity,
    ) {}

    public function store(Request $request, Attachment $attachment): JsonResponse
    {
        $this->authorizeWrite($request, $attachment);
        abort_unless($attachment->isVideoAsset(), 422, 'Captions go on videos.');
        abort_if($attachment->captions()->count() >= self::MAX_PER_VIDEO, 422, 'Up to '.self::MAX_PER_VIDEO.' caption tracks per video.');
        $data = $request->validate([
            'file' => ['required', 'file', 'max:1024'],
            'label' => ['nullable', 'string', 'max:60'],
            'language' => ['nullable', 'string', 'max:12', 'regex:/^[A-Za-z]{2,3}(-[A-Za-z0-9]{2,8})?$/'],
        ]);
        $file = $request->file('file');
        abort_unless($file instanceof UploadedFile, 422);
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['vtt', 'srt'], true)) {
            throw ValidationException::withMessages(['file' => __('Captions must be a .vtt or .srt file.')]);
        }
        try {
            $parsed = Captions::toVtt((string) file_get_contents((string) $file->getRealPath()));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $key = 'captions/'.$attachment->workspace_id.'/'.Str::uuid()->toString().'.vtt';
        $this->store->putContents($key, $parsed['vtt']);
        /** @var User $user */
        $user = $request->user();
        $caption = AttachmentCaption::query()->create([
            'attachment_id' => $attachment->id,
            'label' => trim((string) ($data['label'] ?? '')) ?: (isset($data['language']) ? strtoupper((string) $data['language']) : 'Captions'),
            'language' => $data['language'] ?? null,
            'key' => $key,
            'cues' => $parsed['cues'],
            'created_by' => $user->id,
        ]);
        $this->activity->log('file.captions-added', $attachment, ['caption_id' => $caption->id, 'cues' => $caption->cues], $user);

        return response()->json(['captions' => AttachmentPresenter::captions($attachment->fresh() ?? $attachment)], 201);
    }

    public function destroy(Request $request, AttachmentCaption $caption): JsonResponse
    {
        $attachment = Attachment::query()->findOrFail($caption->attachment_id);
        $this->authorizeWrite($request, $attachment);
        $this->store->delete($caption->key);
        $caption->delete();

        return response()->json(['captions' => AttachmentPresenter::captions($attachment)]);
    }

    /** Signed link for the player's <track> (same origin, so no CORS). */
    public function show(Request $request, AttachmentCaption $caption): Response
    {
        $attachment = Attachment::query()->findOrFail($caption->attachment_id);
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::query()->find($attachment->workspace_id);
        abort_unless($workspace !== null && $this->access->canView($user, $workspace), 403);
        abort_unless($this->store->exists($caption->key), 404);

        return $this->store->response($caption->key, (Str::slug($caption->label) ?: 'captions').'.vtt', 'text/vtt; charset=utf-8', true);
    }

    private function authorizeWrite(Request $request, Attachment $attachment): void
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::query()->find($attachment->workspace_id);
        $record = $attachment->attachable;
        abort_unless($workspace !== null && ($this->access->canContribute($user, $workspace)
            || ($record !== null && app(AttachmentPresenter::class)->canApprove($record, $workspace, $user))), 403);
    }
}
