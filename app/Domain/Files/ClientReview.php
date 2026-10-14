<?php

namespace App\Domain\Files;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Models\Annotation;
use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * E-08: a client reviews one file version without logging in. They see only their own
 * comment threads (and the agency's replies to them, signed with the agency name), can pin
 * comments on images and approve or request changes on the latest version. Staff see the
 * client's comments marked "Client" and are alerted.
 */
class ClientReview
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly Notifier $notifier,
    ) {}

    public function share(Attachment $attachment, ?int $days, User $by): string
    {
        $token = Str::random(40);
        $attachment->forceFill([
            'review_token' => $token,
            'review_token_expires_at' => $days !== null ? now()->addDays($days) : null,
            'review_views' => 0,
            'review_link_by' => $by->id,
        ])->save();
        $this->activity->log('file.client-link-created', $this->subject($attachment), ['file' => $this->label($attachment), 'expires_days' => $days], $by);

        return $token;
    }

    public function revoke(Attachment $attachment, User $by): void
    {
        $attachment->forceFill(['review_token' => null, 'review_token_expires_at' => null])->save();
        $this->activity->log('file.client-link-revoked', $this->subject($attachment), ['file' => $this->label($attachment)], $by);
    }

    /** The version behind a live link, or null (unknown, revoked, expired, client deleted). */
    public function find(string $token): ?Attachment
    {
        if (strlen($token) !== 40 || ! ctype_alnum($token)) {
            return null;
        }
        $attachment = Attachment::query()->where('review_token', $token)->first();
        if ($attachment === null || ($attachment->review_token_expires_at !== null && $attachment->review_token_expires_at->isPast())) {
            return null;
        }

        return Workspace::query()->whereKey($attachment->workspace_id)->exists() ? $attachment : null;
    }

    /**
     * What the client sees: their threads only; agency replies signed with the agency name.
     *
     * @return list<array{id: int, number: int|null, x: float|null, y: float|null, body: string, author: string, resolved: bool, at: string|null, replies: list<array{body: string, author: string, at: string|null}>}>
     */
    public function threads(Attachment $attachment, string $agency): array
    {
        return Annotation::query()->with('replies')
            ->where('attachment_id', $attachment->id)->whereNull('parent_id')->whereNotNull('client_name')
            ->orderBy('number')->get()
            ->map(fn (Annotation $a): array => [
                'id' => $a->id,
                'number' => $a->number,
                'x' => $a->x !== null ? (float) $a->x : null,
                'y' => $a->y !== null ? (float) $a->y : null,
                'body' => $a->body,
                'author' => (string) $a->client_name,
                'resolved' => $a->resolved_at !== null,
                'at' => $a->created_at?->toIso8601String(),
                'replies' => $a->replies->map(fn (Annotation $r): array => [
                    'body' => $r->body,
                    'author' => $r->client_name ?? $agency,
                    'at' => $r->created_at?->toIso8601String(),
                ])->values()->all(),
            ])->values()->all();
    }

    /**
     * A client pin (x, y on images) or a reply on one of the client's own threads.
     */
    public function comment(Attachment $attachment, string $name, string $body, ?float $x, ?float $y, ?int $parentId): Annotation
    {
        if ($parentId !== null) {
            $parent = Annotation::query()->where('attachment_id', $attachment->id)->whereNull('parent_id')->whereNotNull('client_name')->find($parentId);
            if ($parent === null) {
                throw ValidationException::withMessages(['body' => __('That comment is not on this file.')]);
            }
        }
        if (! $attachment->isImage()) {
            $x = $y = null; // pins only make sense on images; other files get whole-file comments
        }

        $annotation = DB::transaction(function () use ($attachment, $name, $body, $x, $y, $parentId) {
            $number = $parentId !== null ? null
                : (int) Annotation::query()->where('attachment_id', $attachment->id)->whereNull('parent_id')->lockForUpdate()->max('number') + 1;

            return Annotation::create([
                'attachment_id' => $attachment->id,
                'parent_id' => $parentId,
                'user_id' => null,
                'client_name' => $name,
                'number' => $number,
                'x' => $x !== null ? round($x, 5) : null,
                'y' => $y !== null ? round($y, 5) : null,
                'body' => $body,
            ]);
        });

        $this->activity->log('file.client-commented', $this->subject($attachment), ['file' => $this->label($attachment), 'client' => $name, 'pin' => $annotation->number]);
        $this->alertStaff($attachment, NotificationKind::Comment, __(':client (client) commented on :file', ['client' => $name, 'file' => $this->label($attachment)]), mb_substr($body, 0, 160));

        return $annotation;
    }

    /**
     * The client's decision on the latest version: approve, or request changes with a note.
     */
    public function decide(Attachment $attachment, string $name, string $decision, ?string $note): void
    {
        if (! $attachment->is_latest) {
            throw ValidationException::withMessages(['decision' => __('A newer version was shared since. Please review the latest one.')]);
        }
        $approve = $decision === 'approve';
        $attachment->forceFill([
            'approved_at' => $approve ? now() : null,
            'approved_by' => null,
            'review_status' => $approve ? 'approved' : 'changes',
            'review_note' => $approve ? null : $note,
            'client_decision_by' => $name,
        ])->save();

        $this->activity->log($approve ? 'file.client-approved' : 'file.client-changes-requested', $this->subject($attachment),
            ['file' => $this->label($attachment), 'client' => $name, 'note' => $note]);
        $this->alertStaff($attachment, NotificationKind::Review,
            $approve ? __(':client (client) approved :file', ['client' => $name, 'file' => $this->label($attachment)])
                : __(':client (client) asked for changes on :file', ['client' => $name, 'file' => $this->label($attachment)]),
            $note);
    }

    private function alertStaff(Attachment $attachment, NotificationKind $kind, string $title, ?string $body): void
    {
        $workspace = Workspace::query()->find($attachment->workspace_id);
        if ($workspace === null) {
            return;
        }
        $record = $attachment->loadMissing('attachable')->attachable;
        $task = $record instanceof Task ? $record : null;
        $ids = array_filter([$attachment->review_link_by, $attachment->uploaded_by, $task?->assignee_id]);
        if ($task !== null) {
            $ids = [...$ids, ...$task->reviewers()->pluck('user_id')->map(fn ($id) => (int) $id)->all()];
        }
        $this->notifier->send($ids, $kind, $workspace, [
            'title' => $title,
            'body' => $body,
            'url' => $task !== null ? route('workspaces.tasks.show', [$workspace, $task], false) : route('workspaces.show', $workspace, false),
            'task_id' => $task?->id,
        ]);
    }

    private function subject(Attachment $attachment): Task|Attachment
    {
        $record = $attachment->loadMissing('attachable')->attachable;

        return $record instanceof Task ? $record : $attachment;
    }

    private function label(Attachment $attachment): string
    {
        return $attachment->original_name.' v'.$attachment->version;
    }
}
