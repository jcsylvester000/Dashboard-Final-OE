<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\DriveFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A file in a team member's drive ("My files"), stored on UploadThing.
 *
 * @property int $id
 * @property int $owner_id
 * @property int|null $workspace_id
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property string $storage
 * @property string|null $storage_key
 * @property string $custom_id
 * @property string $status
 * @property string $visibility
 * @property string|null $share_token
 * @property CarbonInterface|null $share_expires_at
 * @property int $share_downloads
 * @property Carbon|null $locked_at
 * @property int|null $locked_by
 * @property Carbon|null $uploaded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['owner_id', 'workspace_id', 'name', 'mime', 'size', 'storage', 'storage_key', 'custom_id', 'status', 'visibility'])]
class DriveFile extends Model
{
    /** @use HasFactory<DriveFileFactory> */
    use HasFactory, SoftDeletes;

    public const PENDING = 'pending';

    public const READY = 'ready';

    /** Who can open the file (besides the owner and admins). */
    public const VISIBILITIES = [
        'private' => 'Private (only me)',
        'team' => 'Team (all staff)',
        'workspace' => 'Workspace team (agency staff on one client)',
        'public' => 'Public link (anyone with the link)',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'share_downloads' => 'integer',
            'share_expires_at' => 'datetime',
            'locked_at' => 'datetime',
            'uploaded_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @param  Builder<DriveFile>  $query
     */
    public function scopeReady(Builder $query): void
    {
        $query->where('status', self::READY);
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /** The public link works: public, uploaded, token set and not expired. */
    public function shareIsLive(): bool
    {
        return $this->visibility === 'public'
            && $this->status === self::READY
            && $this->share_token !== null
            && ($this->share_expires_at === null || $this->share_expires_at->isFuture());
    }
}
