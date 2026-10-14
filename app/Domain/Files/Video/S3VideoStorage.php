<?php

namespace App\Domain\Files\Video;

use App\Models\Attachment;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

/**
 * Cloudflare R2 (or any S3-compatible bucket), private. The browser uploads parts
 * with presigned PUTs and plays with presigned GETs; PHP never holds the bytes.
 */
class S3VideoStorage implements VideoStorage
{
    public function __construct(private readonly S3Client $s3, private readonly int $linkMinutes = 15) {}

    public function name(): string
    {
        return 'video-s3';
    }

    public function start(string $key, string $mime): string
    {
        return $this->s3->createMultipart($key, $mime);
    }

    public function partUrl(Attachment $attachment, int $part): string
    {
        return $this->s3->presign('PUT', $attachment->key, $this->linkMinutes * 60, [
            'partNumber' => (string) $part,
            'uploadId' => (string) $attachment->upload_id,
        ]);
    }

    public function complete(Attachment $attachment, array $parts): void
    {
        $this->s3->completeMultipart($attachment->key, (string) $attachment->upload_id, $parts);
    }

    public function abort(Attachment $attachment): void
    {
        if ($attachment->upload_id !== null) {
            $this->s3->abortMultipart($attachment->key, $attachment->upload_id);
        }
    }

    public function size(string $key): ?int
    {
        return $this->s3->size($key);
    }

    public function respond(string $key, string $filename, string $mime, bool $inline): RedirectResponse
    {
        $disposition = ($inline ? 'inline' : 'attachment').'; filename="'.addcslashes($filename, '"\\').'"; filename*=UTF-8\'\''.rawurlencode($filename);

        return redirect()->away($this->s3->presign('GET', $key, $this->linkMinutes * 60, [
            'response-content-type' => $mime,
            'response-content-disposition' => $disposition,
        ]));
    }

    public function localCopy(string $key): string
    {
        $path = tempnam(sys_get_temp_dir(), 'vid');
        if ($path === false) {
            throw new RuntimeException('No temporary space for the video.');
        }
        $this->s3->getToFile($key, $path);

        return $path;
    }

    public function release(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function putFile(string $key, string $path, string $mime): void
    {
        $this->s3->putFile($key, $path, $mime);
    }

    public function delete(string $key): void
    {
        $this->s3->delete($key);
    }
}
