<?php

namespace App\Domain\Files;

use App\Domain\Files\UploadThing\UploadThingClient;
use App\Domain\Files\UploadThing\UploadThingException;
use App\Domain\Files\Video\VideoStorage;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Settings\AppSettings;
use App\Models\Attachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Upload rules (type + size checked on the server, name sanitised), storage,
 * WebP thumbnails for images and signed, expiring download links.
 */
class AttachmentService
{
    public function __construct(
        private readonly FileStore $store,
        private readonly AppSettings $settings,
        private readonly ActivityLogger $activity,
        private readonly UploadThingClient $uploadThing,
    ) {}

    /**
     * R-03 / D-06: attachments go to UploadThing only when files there are private,
     * unless FILES_DRIVER forces a choice. Client briefs and contracts must never be
     * public-by-URL by default.
     */
    public function usesUploadThing(): bool
    {
        $driver = (string) config('files.driver', 'auto');
        if ($driver === 'local' || ! $this->uploadThing->configured()) {
            return false;
        }

        return $driver === 'uploadthing' || config('services.uploadthing.acl') === 'private';
    }

    public function maxMb(): int
    {
        return max(1, (int) $this->settings->get('files.max_mb', config('files.max_mb', 25)));
    }

    /**
     * @return list<string>
     */
    public function allowedGroups(): array
    {
        $groups = $this->settings->get('files.groups', config('files.default_groups'));

        return array_values(array_intersect((array) $groups, array_keys((array) config('files.groups'))));
    }

    /**
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $ext = [];
        foreach ($this->allowedGroups() as $group) {
            $ext = [...$ext, ...(array) config("files.groups.$group.ext")];
        }

        return array_values(array_unique($ext));
    }

    /**
     * Validation rules for an upload field (Laravel checks the real MIME type of the contents).
     *
     * @return list<string>
     */
    public function rules(): array
    {
        return ['required', 'file', 'max:'.($this->maxMb() * 1024), 'mimes:'.implode(',', $this->allowedExtensions())];
    }

    /**
     * @param  Attachment|null  $versionOf  E-02: upload as the next version of this file's stack
     */
    public function attach(Model $attachable, int $workspaceId, UploadedFile $file, User $by, ?Attachment $versionOf = null): Attachment
    {
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $name = self::sanitiseName($file->getClientOriginalName(), $file->guessExtension());
        if ($this->usesUploadThing()) {
            try {
                $acl = config('services.uploadthing.acl');
                $key = $this->uploadThing->uploadFile((string) $file->getRealPath(), $name, (int) $file->getSize(), $mime, 'att-'.Str::uuid()->toString(), is_string($acl) && $acl !== '' ? $acl : null);
                $disk = 'uploadthing';
            } catch (UploadThingException $e) {
                // Never lose the upload: fall back to the private local disk.
                report($e);
                $key = $this->store->put($file, 'attachments/'.$workspaceId);
                $disk = $this->store->name();
            }
        } else {
            $key = $this->store->put($file, 'attachments/'.$workspaceId);
            $disk = $this->store->name();
        }

        $stack = null;
        $version = 1;
        if ($versionOf !== null) {
            $stack = $versionOf->stackId();
            $version = 1 + (int) Attachment::query()->where(fn ($q) => $q->whereKey($stack)->orWhere('version_root_id', $stack))->max('version');
            Attachment::query()->where(fn ($q) => $q->whereKey($stack)->orWhere('version_root_id', $stack))->update(['is_latest' => false]);
        }

        $attachment = Attachment::create([
            'version_root_id' => $stack,
            'version' => $version,
            'is_latest' => true,
            'workspace_id' => $workspaceId,
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'uploaded_by' => $by->id,
            'disk' => $disk,
            'key' => $key,
            'original_name' => $name,
            'mime' => $mime,
            'size' => (int) $file->getSize(),
        ]);

        if (str_starts_with($mime, 'image/')) {
            $this->makeThumbnail($attachment, (string) $file->getRealPath());
        }

        $this->activity->log($version > 1 ? 'file.version-uploaded' : 'file.uploaded', $attachable, ['attachment_id' => $attachment->id, 'name' => $attachment->original_name, 'version' => $version], $by);

        return $attachment;
    }

    public function delete(Attachment $attachment, User $by): void
    {
        // Linked from someone's drive: the bytes belong to that drive file, keep them.
        if ($attachment->drive_file_id === null && $attachment->disk === $this->store->name()) {
            $this->store->delete($attachment->key);
        }
        if ($attachment->drive_file_id === null && $attachment->disk === 'uploadthing') {
            try {
                $this->uploadThing->deleteFiles([$attachment->key]);
            } catch (UploadThingException $e) {
                report($e); // the row goes anyway; an orphaned remote file is cleaned up by hand
            }
        }
        // E-11: videos live in video storage (original + web proxy).
        if ($attachment->isVideoAsset()) {
            $video = app(VideoStorage::class);
            try {
                $video->delete($attachment->key);
                if ($attachment->proxy_key !== null) {
                    $video->delete($attachment->proxy_key);
                }
            } catch (\Throwable $e) {
                report($e); // the row goes anyway; storage lifecycle rules catch leftovers
            }
        }
        if ($attachment->thumbnail_key !== null) {
            $this->store->delete($attachment->thumbnail_key);
        }
        $attachment->delete();
        // E-02: removing the newest version makes the previous one the latest again.
        if ($attachment->is_latest) {
            $stack = $attachment->stackId();
            $previous = Attachment::query()->where(fn ($q) => $q->whereKey($stack)->orWhere('version_root_id', $stack))
                ->orderByDesc('version')->first();
            $previous?->forceFill(['is_latest' => true])->save();
        }
        $this->activity->log('file.deleted', null, ['attachment_id' => $attachment->id, 'name' => $attachment->original_name], $by);
    }

    /**
     * Signed link that stops working after config('files.link_minutes').
     */
    public function url(Attachment $attachment, bool $download = false, bool $thumbnail = false, bool $text = false): string
    {
        $params = ['attachment' => $attachment->id];
        if ($download) {
            $params['download'] = 1;
        }
        if ($text) {
            $params['text'] = 1; // E-23: same-origin plain text for the log viewer
        }
        $expires = now()->addMinutes((int) config('files.link_minutes', 10));
        if ($thumbnail) {
            $params['thumb'] = 1;
            // E-126: thumbnail links expire on a 10-minute boundary, so every page shown in
            // that window uses the same URL and the browser serves the image from its cache.
            $bucket = 600;
            $expires = $expires->setTimestamp((intdiv($expires->getTimestamp(), $bucket) + 1) * $bucket);
        }

        return URL::temporarySignedRoute('files.show', $expires, $params, false);
    }

    /**
     * Keep letters, digits, dash, underscore and dot; never trust the client's extension.
     */
    public static function sanitiseName(string $name, ?string $realExtension): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = Str::of(Str::ascii($base))->replaceMatches('/[^A-Za-z0-9 _.-]+/', '')->squish()->limit(120, '')->trim(' .')->toString();
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($realExtension ?: pathinfo($name, PATHINFO_EXTENSION))) ?: 'bin';

        return ($base !== '' ? $base : 'file').'.'.$ext;
    }

    /**
     * 400px-wide WebP preview (needs the GD extension with WebP; skipped quietly without it).
     */
    private function makeThumbnail(Attachment $attachment, string $path): void
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagewebp') || ! is_readable($path)) {
            return;
        }

        // Skip huge pixel counts (decompression bombs would exhaust memory).
        $info = @getimagesize($path);
        if ($info === false || $info[0] * $info[1] > 40_000_000) {
            return;
        }

        $raw = @file_get_contents($path);
        $image = $raw !== false ? @imagecreatefromstring($raw) : false;
        if ($image === false) {
            return;
        }

        $width = imagesx($image);
        $thumb = $width > 400 ? imagescale($image, 400) : $image;
        if ($thumb === false) {
            return;
        }

        ob_start();
        imagewebp($thumb, null, 80);
        $bytes = (string) ob_get_clean();
        if ($bytes === '') {
            return;
        }

        $key = 'thumbnails/'.$attachment->workspace_id.'/'.Str::uuid()->toString().'.webp';
        $this->store->putContents($key, $bytes);
        $attachment->forceFill(['thumbnail_key' => $key])->save();
    }
}
