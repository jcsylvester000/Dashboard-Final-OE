<?php

namespace App\Domain\Files;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files on a private Laravel disk (storage/app/private by default). Never public:
 * every download goes through a signed, expiring app URL.
 */
class LocalFileStore implements FileStore
{
    public function __construct(private readonly string $disk = 'local') {}

    public function name(): string
    {
        return 'local';
    }

    public function put(UploadedFile $file, string $directory): string
    {
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($file->guessExtension() ?: $file->getClientOriginalExtension())) ?: 'bin';
        $key = trim($directory, '/').'/'.Str::uuid()->toString().'.'.$ext;
        $this->disk()->putFileAs(dirname($key), $file, basename($key));

        return $key;
    }

    public function putContents(string $key, string $contents): void
    {
        $this->disk()->put($key, $contents);
    }

    public function response(string $key, string $filename, string $mime, bool $inline): StreamedResponse
    {
        $disposition = $inline ? 'inline' : 'attachment';

        return $this->disk()->response($key, $filename, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=600',
        ], $disposition);
    }

    public function delete(string $key): void
    {
        $this->disk()->delete($key);
    }

    public function exists(string $key): bool
    {
        return $this->disk()->exists($key);
    }

    private function disk(): FilesystemAdapter
    {
        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($this->disk);

        return $disk;
    }
}
