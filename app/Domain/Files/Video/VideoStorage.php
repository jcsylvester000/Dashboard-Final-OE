<?php

namespace App\Domain\Files\Video;

use App\Models\Attachment;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where video deliverables live (D-01). Uploads arrive in parts straight from the
 * browser; playback is a short-lived link that supports HTTP Range (206).
 */
interface VideoStorage
{
    /** Saved as the attachment's disk ("video-s3" | "video-local"). */
    public function name(): string;

    /** Start a multipart upload; returns the upload id. */
    public function start(string $key, string $mime): string;

    /** URL the browser PUTs one part to (1-based part number). */
    public function partUrl(Attachment $attachment, int $part): string;

    /**
     * @param  list<array{part: int, etag: string}>  $parts
     */
    public function complete(Attachment $attachment, array $parts): void;

    public function abort(Attachment $attachment): void;

    /** Stored size in bytes, or null when missing. */
    public function size(string $key): ?int;

    /** Send (or redirect to) the file; Range requests answer 206. */
    public function respond(string $key, string $filename, string $mime, bool $inline): Response;

    /** A readable local path for ffmpeg; call release() afterwards. */
    public function localCopy(string $key): string;

    public function release(string $path): void;

    public function putFile(string $key, string $path, string $mime): void;

    public function delete(string $key): void;
}
