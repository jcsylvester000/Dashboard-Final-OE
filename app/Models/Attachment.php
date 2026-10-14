<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property string|null $review_token E-08: client review link (no login)
 * @property Carbon|null $review_token_expires_at
 * @property int $review_views
 * @property int|null $review_link_by staff member who shared the link
 * @property string|null $client_decision_by E-08: client's name on their approve / request changes
 * @property string|null $upload_state E-11: "uploading" until every part is in storage
 * @property string|null $upload_id storage multipart upload id
 * @property string|null $media_status E-13: processing | ready | failed
 * @property array{duration_ms?: int, width?: int, height?: int, fps?: float, video_codec?: string, audio_codec?: string|null}|null $media
 * @property string|null $media_error
 * @property string|null $proxy_key E-13: web-playable copy of a video
 * @property Carbon|null $created_at
 */
#[Fillable(['workspace_id', 'attachable_type', 'attachable_id', 'uploaded_by', 'disk', 'key', 'original_name', 'mime', 'size', 'thumbnail_key', 'drive_file_id', 'version_root_id', 'version', 'is_latest',
    'upload_state', 'upload_id', 'media_status', 'media', 'media_error', 'proxy_key'])]
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
        return ['size' => 'integer', 'approved_at' => 'datetime', 'version' => 'integer', 'is_latest' => 'boolean', 'version_root_id' => 'integer',
            'review_token_expires_at' => 'datetime', 'review_views' => 'integer', 'media' => 'array'];
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

    /** E-11: a video stored through VideoStorage (direct upload, processed by the media job). */
    public function isVideoAsset(): bool
    {
        return in_array($this->disk, ['video-s3', 'video-local'], true);
    }

    /** E-23: plain-text files (logs, JSON, CSV, Markdown) open in the text viewer. */
    public function isText(): bool
    {
        $ext = strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION));

        return in_array($ext, ['txt', 'log', 'json', 'csv', 'md'], true);
    }

    /** The id shared by every version of this file. */
    public function stackId(): int
    {
        return $this->version_root_id ?? $this->id;
    }

    /** @return HasMany<AttachmentCaption, $this> E-19: caption tracks on this video version */
    public function captions(): HasMany
    {
        return $this->hasMany(AttachmentCaption::class)->orderBy('id');
    }

    /** @return HasMany<Annotation, $this> E-04: pins and drawings on this version */
    public function annotations(): HasMany
    {
        return $this->hasMany(Annotation::class);
    }
}
