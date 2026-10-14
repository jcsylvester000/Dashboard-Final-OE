<?php

namespace App\Domain\Files;

use App\Models\Attachment;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Attachment rows for pages, with fresh signed links.
 */
class AttachmentPresenter
{
    public function __construct(private readonly AttachmentService $files) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function for(Model $record, Workspace $workspace, User $user, bool $readOnly = false): array
    {
        $canLead = Gate::forUser($user)->allows('lead', $workspace);
        $canApprove = ! $readOnly && $this->canApprove($record, $workspace, $user);

        $all = Attachment::query()
            ->with(['uploader:id,name', 'approver:id,name'])
            // E-04: open pins per version, for the badge on each file.
            ->withCount(['annotations as open_pins' => fn ($q) => $q->whereNull('parent_id')->whereNull('resolved_at')])
            ->where('attachable_type', $record->getMorphClass())
            ->where('attachable_id', $record->getKey())
            ->get();
        // E-02: one row per file (its latest version) with the older versions inside.
        $byStack = $all->groupBy(fn (Attachment $a) => $a->stackId());

        return $all->where('is_latest', true)
            // Approved files first, then newest.
            ->sortBy(fn (Attachment $a) => [$a->approved_at === null ? 1 : 0, -$a->id])
            ->map(fn (Attachment $a): array => [
                'id' => $a->id,
                'version' => $a->version,
                'review_status' => $a->review_status,
                'review_note' => $a->review_note,
                'versions' => $byStack->get($a->stackId(), collect())
                    ->where('id', '!=', $a->id)
                    ->sortByDesc('version')
                    ->map(fn (Attachment $v): array => [
                        'id' => $v->id,
                        'version' => $v->version,
                        'name' => $v->original_name,
                        'mime' => $v->mime,
                        'uploader' => $v->uploader?->name,
                        'created_at' => $v->created_at?->toIso8601String(),
                        'url' => $this->files->url($v),
                        'download_url' => $this->files->url($v, download: true),
                        'approved' => $v->approved_at !== null,
                        'review_status' => $v->review_status,
                        'review_note' => $v->review_note,
                    ])->values()->all(),
                'name' => $a->original_name,
                'mime' => $a->mime,
                'size' => $a->size,
                'uploader' => $a->uploader?->name,
                'created_at' => $a->created_at?->toIso8601String(),
                'url' => $this->files->url($a),
                'download_url' => $this->files->url($a, download: true),
                'thumb_url' => $a->thumbnail_key !== null ? $this->files->url($a, thumbnail: true) : null,
                'previewable' => $a->previewable(),
                'can_delete' => ! $readOnly && ($a->uploaded_by === $user->id || $canLead),
                'approved' => $a->approved_at !== null,
                'approved_by' => $a->approver?->name,
                'approved_at' => $a->approved_at?->toIso8601String(),
                'can_approve' => $canApprove,
                'from_drive' => $a->drive_file_id !== null,
                'open_pins' => (int) $a->getAttribute('open_pins'),
            ])
            ->values()
            ->all();
    }

    /**
     * Read-only roll-up of the files on many tasks (project page), approved first.
     *
     * @param  list<int>  $taskIds
     * @return list<array<string, mixed>>
     */
    public function forTasks(array $taskIds, int $limit = 60): array
    {
        if ($taskIds === []) {
            return [];
        }

        return Attachment::query()
            ->with(['uploader:id,name', 'approver:id,name', 'attachable:id,title'])
            ->where('attachable_type', (new Task)->getMorphClass())
            ->whereIn('attachable_id', $taskIds)
            ->where('is_latest', true)
            ->orderByRaw('approved_at is null')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (Attachment $a): array => [
                'id' => $a->id,
                'name' => $a->original_name,
                'mime' => $a->mime,
                'size' => $a->size,
                'uploader' => $a->uploader?->name,
                'created_at' => $a->created_at?->toIso8601String(),
                'url' => $this->files->url($a),
                'download_url' => $this->files->url($a, download: true),
                'thumb_url' => $a->thumbnail_key !== null ? $this->files->url($a, thumbnail: true) : null,
                'previewable' => $a->previewable(),
                'can_delete' => false,
                'approved' => $a->approved_at !== null,
                'approved_by' => $a->approver?->name,
                'approved_at' => $a->approved_at?->toIso8601String(),
                'can_approve' => false,
                'task' => $a->attachable instanceof Task ? ['id' => $a->attachable->id, 'title' => $a->attachable->title] : null,
            ])
            ->values()
            ->all();
    }

    /**
     * Who may mark a file approved: task reviewers (see TaskPolicy::review) for task
     * files, workspace leads for everything else.
     */
    public function canApprove(Model $record, Workspace $workspace, User $user): bool
    {
        return $record instanceof Task
            ? Gate::forUser($user)->allows('review', $record)
            : Gate::forUser($user)->allows('lead', $workspace);
    }

    /**
     * Upload limits for the UI hint.
     *
     * @return array{max_mb: int, extensions: list<string>}
     */
    public function limits(): array
    {
        return ['max_mb' => $this->files->maxMb(), 'extensions' => $this->files->allowedExtensions()];
    }
}
