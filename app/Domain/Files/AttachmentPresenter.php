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

        return Attachment::query()
            ->with(['uploader:id,name', 'approver:id,name'])
            ->where('attachable_type', $record->getMorphClass())
            ->where('attachable_id', $record->getKey())
            // Approved files first, then newest.
            ->orderByRaw('approved_at is null')
            ->latest('id')
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
                'can_delete' => ! $readOnly && ($a->uploaded_by === $user->id || $canLead),
                'approved' => $a->approved_at !== null,
                'approved_by' => $a->approver?->name,
                'approved_at' => $a->approved_at?->toIso8601String(),
                'can_approve' => $canApprove,
                'from_drive' => $a->drive_file_id !== null,
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
