<?php

namespace App\Domain\Files;

use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * The private local disk (attachments when UploadThing is off or public, and all
 * thumbnails). UploadThing uploads go through UploadThingClient in AttachmentService.
 */
interface FileStore
{
    /** Driver name saved on each attachment (so old files keep working after a switch). */
    public function name(): string;

    /** Store the upload under a generated key and return that key. */
    public function put(UploadedFile $file, string $directory): string;

    /** Store raw bytes (e.g. a generated thumbnail) at the given key. */
    public function putContents(string $key, string $contents): void;

    /** Send the file to the browser (E-12: Range requests answer 206 where the disk allows). */
    public function response(string $key, string $filename, string $mime, bool $inline): Response;

    /** The stored bytes, or null when missing (E-09 feedback sheet). */
    public function contents(string $key): ?string;

    public function delete(string $key): void;

    public function exists(string $key): bool;
}
