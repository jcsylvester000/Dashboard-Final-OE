<?php

namespace App\Domain\Files;

use App\Models\Attachment;
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
    public function for(Model $record, Workspace $workspace, User $user): array
    {
        $canLead = Gate::forUser($user)->allows('lead', $workspace);

        return Attachment::query()
            ->with('uploader:id,name')
            ->where('attachable_type', $record->getMorphClass())
            ->where('attachable_id', $record->getKey())
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
                'can_delete' => $a->uploaded_by === $user->id || $canLead,
            ])
            ->values()
            ->all();
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
