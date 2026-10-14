<?php

namespace App\Domain\Files\Video;

use App\Domain\Files\AttachmentService;
use App\Domain\Identity\ActivityLogger;
use App\Jobs\ProcessVideo;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * E-11: large videos go from the browser straight to storage in parts.
 * start -> part URLs -> complete (size checked) -> media job (E-13).
 * Quota per client is checked before the first byte is sent.
 */
class VideoUploads
{
    public const MIMES = [
        'mp4' => 'video/mp4',
        'm4v' => 'video/x-m4v',
        'mov' => 'video/quicktime',
        'webm' => 'video/webm',
        'mkv' => 'video/x-matroska',
    ];

    public function __construct(
        private readonly VideoStorage $storage,
        private readonly ActivityLogger $activity,
    ) {}

    public static function isVideoName(string $name): bool
    {
        return in_array(self::extension($name), (array) config('video.extensions', []), true);
    }

    public static function partBytes(): int
    {
        return max(5, (int) config('video.part_mb', 16)) * 1024 * 1024;
    }

    public static function maxBytes(): int
    {
        return (int) round((float) config('video.max_gb', 5) * 1024 ** 3);
    }

    public static function quotaBytes(): int
    {
        return (int) round((float) config('video.quota_gb', 50) * 1024 ** 3);
    }

    /** Bytes of video this client uses (every version, unfinished uploads included). */
    public function used(int $workspaceId): int
    {
        return (int) Attachment::query()->where('workspace_id', $workspaceId)
            ->whereIn('disk', ['video-s3', 'video-local'])->sum('size');
    }

    /**
     * @return array{id: int, part_size: int, parts: int}
     */
    public function start(Model $attachable, int $workspaceId, User $by, string $name, int $size, ?Attachment $versionOf = null): array
    {
        $ext = self::extension($name);
        if (! self::isVideoName($name)) {
            throw ValidationException::withMessages(['file' => __('Videos can be :types.', ['types' => implode(', ', (array) config('video.extensions', []))])]);
        }
        if ($size < 1 || $size > self::maxBytes()) {
            throw ValidationException::withMessages(['file' => __('Videos can be at most :gb GB.', ['gb' => config('video.max_gb')])]);
        }
        $used = $this->used($workspaceId);
        if ($used + $size > self::quotaBytes()) {
            throw ValidationException::withMessages(['file' => __('Not enough video space for this client: :free GB free of :quota GB. Delete old versions or ask an admin to raise VIDEO_QUOTA_GB.', [
                'free' => number_format(max(0, self::quotaBytes() - $used) / 1024 ** 3, 1),
                'quota' => config('video.quota_gb'),
            ])]);
        }

        $mime = self::MIMES[$ext] ?? 'application/octet-stream';
        $key = 'videos/'.$workspaceId.'/'.Str::uuid()->toString().'.'.$ext;
        $uploadId = $this->storage->start($key, $mime);

        $stack = null;
        $version = 1;
        if ($versionOf !== null) {
            $stack = $versionOf->stackId();
            $version = 1 + (int) Attachment::query()->where(fn ($q) => $q->whereKey($stack)->orWhere('version_root_id', $stack))->max('version');
        }

        $attachment = Attachment::create([
            'version_root_id' => $stack,
            'version' => $version,
            'is_latest' => false, // becomes the latest when the upload completes
            'workspace_id' => $workspaceId,
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'uploaded_by' => $by->id,
            'disk' => $this->storage->name(),
            'key' => $key,
            'original_name' => AttachmentService::sanitiseName($name, $ext),
            'mime' => $mime,
            'size' => $size,
            'upload_state' => 'uploading',
            'upload_id' => $uploadId,
        ]);

        return ['id' => $attachment->id, 'part_size' => self::partBytes(), 'parts' => (int) ceil($size / self::partBytes())];
    }

    /**
     * @param  list<int>  $parts
     * @return array<int, string> part number => URL
     */
    public function partUrls(Attachment $attachment, array $parts): array
    {
        $this->assertUploading($attachment);
        $total = (int) ceil($attachment->size / self::partBytes());
        $urls = [];
        foreach ($parts as $part) {
            if ($part < 1 || $part > $total) {
                throw ValidationException::withMessages(['parts' => __('Part :n is out of range.', ['n' => $part])]);
            }
            $urls[$part] = $this->storage->partUrl($attachment, $part);
        }

        return $urls;
    }

    /**
     * @param  list<array{part: int, etag: string}>  $parts
     */
    public function complete(Attachment $attachment, array $parts, User $by): Attachment
    {
        $this->assertUploading($attachment);
        $expected = (int) ceil($attachment->size / self::partBytes());
        if (count($parts) !== $expected || count(array_unique(array_column($parts, 'part'))) !== $expected) {
            throw ValidationException::withMessages(['parts' => __('Some parts are missing (:got of :want). Try again.', ['got' => count($parts), 'want' => $expected])]);
        }

        try {
            $this->storage->complete($attachment, $parts);
        } catch (Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['parts' => __('Storage could not join the parts. Try the upload again.')]);
        }
        // Never trust the size the browser declared: it decides the quota.
        $stored = $this->storage->size($attachment->key);
        if ($stored !== $attachment->size) {
            $this->storage->delete($attachment->key);
            $attachment->forceDelete();
            throw ValidationException::withMessages(['file' => __('The uploaded file does not match its declared size. Upload it again.')]);
        }

        DB::transaction(function () use ($attachment) {
            if ($attachment->version_root_id !== null) {
                $stack = $attachment->version_root_id;
                Attachment::query()->where(fn ($q) => $q->whereKey($stack)->orWhere('version_root_id', $stack))->update(['is_latest' => false]);
            }
            $attachment->forceFill(['upload_state' => null, 'upload_id' => null, 'is_latest' => true, 'media_status' => 'processing'])->save();
        });

        $this->activity->log($attachment->version > 1 ? 'file.version-uploaded' : 'file.uploaded', $attachment->attachable, [
            'attachment_id' => $attachment->id, 'name' => $attachment->original_name, 'version' => $attachment->version, 'bytes' => $attachment->size,
        ], $by);
        ProcessVideo::dispatch($attachment->id);

        return $attachment;
    }

    /** Cancel an unfinished upload and free its quota. */
    public function abort(Attachment $attachment): void
    {
        $this->assertUploading($attachment);
        try {
            $this->storage->abort($attachment);
        } catch (Throwable $e) {
            report($e); // the bucket lifecycle rule cleans up leftovers
        }
        $attachment->forceDelete();
    }

    /** Uploads left unfinished longer than video.abandon_hours. */
    public function pruneAbandoned(): int
    {
        $count = 0;
        Attachment::query()->where('upload_state', 'uploading')
            ->where('created_at', '<', now()->subHours((int) config('video.abandon_hours', 24)))
            ->each(function (Attachment $a) use (&$count) {
                $this->abort($a);
                $count++;
            });

        return $count;
    }

    private function assertUploading(Attachment $attachment): void
    {
        if ($attachment->upload_state !== 'uploading' || ! $attachment->isVideoAsset()) {
            throw ValidationException::withMessages(['file' => __('This upload is already finished or was cancelled.')]);
        }
    }

    private static function extension(string $name): string
    {
        return strtolower(pathinfo($name, PATHINFO_EXTENSION));
    }
}
