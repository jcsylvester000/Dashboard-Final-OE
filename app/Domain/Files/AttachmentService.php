<?php

namespace App\Domain\Files;

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
    ) {}

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

    public function attach(Model $attachable, int $workspaceId, UploadedFile $file, User $by): Attachment
    {
        $key = $this->store->put($file, 'attachments/'.$workspaceId);
        $mime = (string) ($file->getMimeType() ?: 'application/octet-stream');

        $attachment = Attachment::create([
            'workspace_id' => $workspaceId,
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'uploaded_by' => $by->id,
            'disk' => $this->store->name(),
            'key' => $key,
            'original_name' => self::sanitiseName($file->getClientOriginalName(), $file->guessExtension()),
            'mime' => $mime,
            'size' => (int) $file->getSize(),
        ]);

        if (str_starts_with($mime, 'image/')) {
            $this->makeThumbnail($attachment, (string) $file->getRealPath());
        }

        $this->activity->log('file.uploaded', $attachable, ['attachment_id' => $attachment->id, 'name' => $attachment->original_name], $by);

        return $attachment;
    }

    public function delete(Attachment $attachment, User $by): void
    {
        $this->store->delete($attachment->key);
        if ($attachment->thumbnail_key !== null) {
            $this->store->delete($attachment->thumbnail_key);
        }
        $attachment->delete();
        $this->activity->log('file.deleted', null, ['attachment_id' => $attachment->id, 'name' => $attachment->original_name], $by);
    }

    /**
     * Signed link that stops working after config('files.link_minutes').
     */
    public function url(Attachment $attachment, bool $download = false, bool $thumbnail = false): string
    {
        $params = ['attachment' => $attachment->id];
        if ($download) {
            $params['download'] = 1;
        }
        if ($thumbnail) {
            $params['thumb'] = 1;
        }

        return URL::temporarySignedRoute('files.show', now()->addMinutes((int) config('files.link_minutes', 10)), $params, false);
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
