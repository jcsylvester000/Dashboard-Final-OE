<?php

namespace App\Http\Controllers;

use App\Domain\Files\AttachmentPresenter;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\Annotation;
use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * E-04 / E-05: numbered pins with threads and vector drawings on a file version (JSON;
 * the viewer saves without reloading the page). Anyone who can open the workspace can
 * read them; members, leads and the task's reviewers can add, reply and resolve.
 * The file bytes are never touched.
 */
class AnnotationController extends Controller
{
    public const MAX_SHAPES = 50;

    public const MAX_POINTS = 500;

    public function __construct(
        private readonly WorkspaceAccess $access,
        private readonly AttachmentPresenter $presenter,
    ) {}

    public function index(Request $request, Attachment $attachment): JsonResponse
    {
        $this->workspace($request, $attachment);

        return response()->json(['pins' => $this->threads($attachment), 'can_write' => $this->canWrite($request, $attachment)]);
    }

    public function store(Request $request, Attachment $attachment, Notifier $notifier, ActivityLogger $activity): JsonResponse
    {
        $workspace = $this->workspace($request, $attachment);
        abort_unless($this->canWrite($request, $attachment), 403);
        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
            'parent_id' => ['nullable', 'integer', Rule::exists('annotations', 'id')->where('attachment_id', $attachment->id)->whereNull('parent_id')],
            'x' => ['nullable', 'numeric', 'between:0,1'],
            'y' => ['nullable', 'numeric', 'between:0,1', 'required_with:x'],
            'color' => ['nullable', Rule::in(Annotation::COLORS)],
            'shapes' => ['nullable', 'array', 'max:'.self::MAX_SHAPES],
            'shapes.*.type' => ['required', Rule::in(Annotation::SHAPE_TYPES)],
            'shapes.*.color' => ['required', Rule::in(Annotation::COLORS)],
            'shapes.*.width' => ['nullable', 'integer', 'between:1,12'],
            'shapes.*.points' => ['required', 'array', 'min:1', 'max:'.self::MAX_POINTS],
            'shapes.*.points.*' => ['array', 'size:2'],
            'shapes.*.points.*.*' => ['numeric', 'between:0,1'],
            'shapes.*.text' => ['nullable', 'string', 'max:200'],
            // E-15 / E-16: on a video, the frame (or range) the comment is about.
            'time_ms' => ['nullable', 'integer', 'min:0', 'max:86400000'],
            'end_ms' => ['nullable', 'integer', 'gt:time_ms', 'max:86400000'],
        ]);
        $video = $attachment->isVideoAsset() || str_starts_with($attachment->mime, 'video/');
        if (isset($data['time_ms']) && ! $video) {
            throw ValidationException::withMessages(['time_ms' => __('Timecodes go on videos.')]);
        }
        if (isset($data['end_ms']) && ! isset($data['time_ms'])) {
            throw ValidationException::withMessages(['end_ms' => __('A range needs a start.')]);
        }
        /** @var User $user */
        $user = $request->user();
        $isReply = isset($data['parent_id']);
        if ($isReply && (isset($data['x']) || isset($data['shapes']) || isset($data['time_ms']))) {
            throw ValidationException::withMessages(['parent_id' => __('Replies go on an existing pin.')]);
        }

        $annotation = DB::transaction(function () use ($attachment, $data, $user, $isReply) {
            $number = $isReply ? null : (int) Annotation::query()->where('attachment_id', $attachment->id)->whereNull('parent_id')->lockForUpdate()->max('number') + 1;

            return Annotation::create([
                'attachment_id' => $attachment->id,
                'parent_id' => $data['parent_id'] ?? null,
                'user_id' => $user->id,
                'number' => $number,
                'x' => isset($data['x']) ? round((float) $data['x'], 5) : null,
                'y' => isset($data['y']) ? round((float) $data['y'], 5) : null,
                'time_ms' => isset($data['time_ms']) ? (int) $data['time_ms'] : null,
                'end_ms' => isset($data['end_ms']) ? (int) $data['end_ms'] : null,
                'shapes' => isset($data['shapes']) ? $this->cleanShapes($data['shapes']) : null,
                'color' => $data['color'] ?? null,
                'body' => trim((string) $data['body']),
            ]);
        });

        $record = $attachment->attachable;
        $activity->log('file.annotated', $record instanceof Task ? $record : $attachment, [
            'file' => $attachment->original_name.' v'.$attachment->version,
            'pin' => $annotation->number ?? Annotation::query()->whereKey($annotation->parent_id)->value('number'),
            'reply' => $isReply,
        ], $user);

        // @mentions in the comment reach those people (same token as task comments).
        preg_match_all('/@\[[^\]]+\]\(user:(\d+)\)/', $annotation->body, $m);
        if ($m[1] !== []) {
            $notifier->send(array_map('intval', $m[1]), NotificationKind::Mentioned, $workspace, [
                'title' => __(':n mentioned you on :f', ['n' => $user->name, 'f' => $attachment->original_name]),
                'body' => mb_substr($annotation->body, 0, 160),
                'url' => $record instanceof Task ? route('workspaces.tasks.show', [$workspace, $record], false) : route('workspaces.show', $workspace, false),
                'task_id' => $record instanceof Task ? $record->id : null,
            ], $user);
        }

        return response()->json(['pins' => $this->threads($attachment), 'saved' => $annotation->id], 201);
    }

    public function update(Request $request, Annotation $annotation): JsonResponse
    {
        $attachment = Attachment::query()->findOrFail($annotation->attachment_id);
        $this->workspace($request, $attachment);
        abort_unless($this->canWrite($request, $attachment), 403);
        $data = $request->validate([
            'resolved' => ['sometimes', 'boolean'],
            'body' => ['sometimes', 'string', 'max:2000'],
        ]);
        /** @var User $user */
        $user = $request->user();
        if (array_key_exists('body', $data)) {
            abort_unless($annotation->user_id === $user->id, 403, 'Only the author can edit this comment.');
            $annotation->forceFill(['body' => trim((string) $data['body'])])->save();
        }
        if (array_key_exists('resolved', $data) && $annotation->parent_id === null) {
            $annotation->forceFill($request->boolean('resolved')
                ? ['resolved_at' => now(), 'resolved_by' => $user->id]
                : ['resolved_at' => null, 'resolved_by' => null])->save();
        }

        return response()->json(['pins' => $this->threads($attachment)]);
    }

    public function destroy(Request $request, Annotation $annotation): JsonResponse
    {
        $attachment = Attachment::query()->findOrFail($annotation->attachment_id);
        $workspace = $this->workspace($request, $attachment);
        /** @var User $user */
        $user = $request->user();
        abort_unless($annotation->user_id === $user->id || $this->access->canLead($user, $workspace), 403);
        $annotation->delete();

        return response()->json(['pins' => $this->threads($attachment)]);
    }

    private function workspace(Request $request, Attachment $attachment): Workspace
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::query()->find($attachment->workspace_id);
        abort_unless($workspace !== null && $this->access->canView($user, $workspace), 403);

        return $workspace;
    }

    private function canWrite(Request $request, Attachment $attachment): bool
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::query()->find($attachment->workspace_id);
        $record = $attachment->attachable;

        return $workspace !== null && ($this->access->canContribute($user, $workspace)
            || ($record !== null && $this->presenter->canApprove($record, $workspace, $user)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function threads(Attachment $attachment): array
    {
        return Annotation::query()
            ->with(['author:id,name', 'replies.author:id,name'])
            ->where('attachment_id', $attachment->id)
            ->whereNull('parent_id')
            ->orderBy('number')
            ->get()
            ->map(fn (Annotation $a): array => [
                'id' => $a->id,
                'number' => $a->number,
                'x' => $a->x !== null ? (float) $a->x : null,
                'y' => $a->y !== null ? (float) $a->y : null,
                'color' => $a->color ?? Annotation::COLORS[0],
                'shapes' => $a->shapes ?? [],
                'time_ms' => $a->time_ms,
                'end_ms' => $a->end_ms,
                'carried_from_version' => $a->carried_from_version, // E-17
                'body' => $a->body,
                // E-08: client pins say so (the client's own name, marked Client).
                'author' => $a->client_name ?? ($a->author->name ?? 'Former member'),
                'author_id' => $a->user_id,
                'is_client' => $a->client_name !== null,
                'at' => $a->created_at?->toIso8601String(),
                'resolved' => $a->resolved_at !== null,
                'replies' => $a->replies->map(fn (Annotation $r): array => [
                    'id' => $r->id,
                    'body' => $r->body,
                    'author' => $r->client_name ?? ($r->author->name ?? 'Former member'),
                    'author_id' => $r->user_id,
                    'is_client' => $r->client_name !== null,
                    'at' => $r->created_at?->toIso8601String(),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Keep only known fields; numbers rounded to 4 decimals (0.01% of the image).
     *
     * @param  array<int, mixed>  $shapes
     * @return list<array<string, mixed>>
     */
    private function cleanShapes(array $shapes): array
    {
        $out = [];
        foreach ($shapes as $s) {
            if (! is_array($s)) {
                continue;
            }
            $out[] = array_filter([
                'type' => (string) $s['type'],
                'color' => (string) $s['color'],
                'width' => isset($s['width']) ? (int) $s['width'] : 3,
                'points' => array_map(fn ($p) => [round((float) $p[0], 4), round((float) $p[1], 4)], (array) $s['points']),
                'text' => isset($s['text']) && $s['type'] === 'text' ? mb_substr((string) $s['text'], 0, 200) : null,
            ], fn ($v) => $v !== null);
        }

        return $out;
    }
}
