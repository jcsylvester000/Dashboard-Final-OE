<?php

namespace App\Domain\Files;

use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Where attachment bytes live. LocalFileStore today (private disk); an
 * UploadThing driver (REST v7) plugs in later behind the same interface,
 * chosen by FILES_DRIVER - no code changes elsewhere.
 */
interface FileStore
{
    /** Driver name saved on each attachment (so old files keep working after a switch). */
    public function name(): string;

    /** Store the upload under a generated key and return that key. */
    public function put(UploadedFile $file, string $directory): string;

    /** Store raw bytes (e.g. a generated thumbnail) at the given key. */
    public function putContents(string $key, string $contents): void;

    /** Send the file to the browser. */
    public function response(string $key, string $filename, string $mime, bool $inline): StreamedResponse;

    public function delete(string $key): void;

    public function exists(string $key): bool;
}
