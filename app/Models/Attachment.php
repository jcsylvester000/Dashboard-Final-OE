<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A file attached to a task, project, comment or workspace.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $attachable_type
 * @property int $attachable_id
 * @property int|null $uploaded_by
 * @property string $disk
 * @property string $key
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property string|null $thumbnail_key
 * @property int|null $drive_file_id
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property int|null $version_root_id first file of the version stack (E-02)
 * @property int $version
 * @property bool $is_latest
 * @property string|null $review_status approved | changes (E-06)
 * @property string|null $review_note
 * @property Carbon|null $created_at
 */
#[Fillable(['workspace_id', 'attachable_type', 'attachable_id', 'uploaded_by', 'disk', 'key', 'original_name', 'mime', 'size', 'thumbnail_key', 'drive_file_id', 'version_root_id', 'version', 'is_latest'])]
class Attachment extends Model
{
    use SoftDeletes;

    /**
     * Same defaults as the columns, for new in-memory models.
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['version' => 1, 'is_latest' => true];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['size' => 'integer', 'approved_at' => 'datetime', 'version' => 'integer', 'is_latest' => 'boolean', 'version_root_id' => 'integer'];
    }

    /** @return MorphTo<Model, $this> */
    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /** Shown inline in the browser (images, PDF, video); everything else downloads. */
    public function previewable(): bool
    {
        return $this->isImage() || $this->mime === 'application/pdf' || str_starts_with($this->mime, 'video/');
    }

    /** The id shared by every version of this file. */
    public function stackId(): int
    {
        return $this->version_root_id ?? $this->id;
    }
}
