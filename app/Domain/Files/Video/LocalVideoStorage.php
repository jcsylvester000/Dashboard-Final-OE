<?php

namespace App\Domain\Files\Video;

use App\Models\Attachment;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Development / small-install driver: same part-by-part flow, stored on the private
 * local disk. Parts go to a signed app URL; playback supports Range (206).
 */
class LocalVideoStorage implements VideoStorage
{
    public function __construct(private readonly string $disk = 'local', private readonly int $linkMinutes = 15) {}

    public function name(): string
    {
        return 'video-local';
    }

    public function start(string $key, string $mime): string
    {
        return Str::uuid()->toString();
    }

    public function partUrl(Attachment $attachment, int $part): string
    {
        return URL::temporarySignedRoute('video-uploads.part', now()->addMinutes($this->linkMinutes), ['attachment' => $attachment->id, 'part' => $part], false);
    }

    /** Store one part from a stream; returns its ETag (MD5, like S3). */
    public function storePart(Attachment $attachment, int $part, mixed $stream): string
    {
        $key = $this->partKey($attachment, $part);
        $this->disk()->writeStream($key, $stream);

        return md5_file($this->disk()->path($key)) ?: '';
    }

    public function complete(Attachment $attachment, array $parts): void
    {
        usort($parts, fn (array $a, array $b) => $a['part'] <=> $b['part']);
        $target = $this->disk()->path($attachment->key);
        @mkdir(dirname($target), 0775, true);
        $out = fopen($target, 'wb');
        if ($out === false) {
            throw new RuntimeException('Cannot write the video.');
        }
        try {
            foreach ($parts as $i => $p) {
                $partPath = $this->disk()->path($this->partKey($attachment, $p['part']));
                if ($p['part'] !== $i + 1 || ! is_file($partPath) || md5_file($partPath) !== trim($p['etag'], '"')) {
                    throw new RuntimeException('Part '.$p['part'].' is missing or damaged.');
                }
                $in = fopen($partPath, 'rb');
                if ($in === false) {
                    throw new RuntimeException('Cannot read part '.$p['part'].'.');
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } catch (RuntimeException $e) {
            fclose($out);
            @unlink($target); // never leave half a video behind

            throw $e;
        }
        fclose($out);
        $this->disk()->deleteDirectory($this->partsDir($attachment));
    }

    public function abort(Attachment $attachment): void
    {
        $this->disk()->deleteDirectory($this->partsDir($attachment));
    }

    public function size(string $key): ?int
    {
        return $this->disk()->exists($key) ? $this->disk()->size($key) : null;
    }

    public function respond(string $key, string $filename, string $mime, bool $inline): BinaryFileResponse
    {
        abort_unless($this->disk()->exists($key), 404);
        $response = new BinaryFileResponse($this->disk()->path($key), 200, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=600',
        ], false, null);
        $response->setContentDisposition($inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename, Str::ascii($filename) ?: 'video');

        return $response;
    }

    public function localCopy(string $key): string
    {
        if (! $this->disk()->exists($key)) {
            throw new RuntimeException('The video file is missing.');
        }

        return $this->disk()->path($key);
    }

    public function release(string $path): void
    {
        // The original stays where it is.
    }

    public function putFile(string $key, string $path, string $mime): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read '.basename($path));
        }
        $this->disk()->writeStream($key, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    public function delete(string $key): void
    {
        $this->disk()->delete($key);
    }

    private function partsDir(Attachment $attachment): string
    {
        return 'video-parts/'.$attachment->id;
    }

    private function partKey(Attachment $attachment, int $part): string
    {
        return $this->partsDir($attachment).'/'.str_pad((string) $part, 5, '0', STR_PAD_LEFT);
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($this->disk);

        return $disk;
    }
}
