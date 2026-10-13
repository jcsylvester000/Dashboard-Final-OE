<?php

namespace App\Domain\Files\UploadThing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the UploadThing REST API (SDK v7 era). No callbacks or file
 * routes: the server asks for a presigned URL, the browser PUTs the file to it,
 * and the server confirms the file exists before marking it ready.
 *
 * The v7 token (UPLOADTHING_TOKEN) is base64 JSON: {apiKey, appId, regions}.
 */
class UploadThingClient
{
    /** @var array{apiKey: string, appId: string, regions: list<string>}|null */
    private ?array $token;

    public function __construct(?string $token, private readonly string $apiUrl = 'https://api.uploadthing.com')
    {
        $this->token = self::parseToken($token);
    }

    /**
     * @return array{apiKey: string, appId: string, regions: list<string>}|null
     */
    public static function parseToken(?string $token): ?array
    {
        $token = trim((string) $token, " '\"");
        if ($token === '') {
            return null;
        }

        $decoded = json_decode((string) base64_decode($token, true), true);
        if (! is_array($decoded) || ! is_string($decoded['apiKey'] ?? null) || ! is_string($decoded['appId'] ?? null)) {
            return null;
        }

        return [
            'apiKey' => $decoded['apiKey'],
            'appId' => $decoded['appId'],
            'regions' => array_values(array_filter((array) ($decoded['regions'] ?? []), 'is_string')),
        ];
    }

    public function configured(): bool
    {
        return $this->token !== null;
    }

    public function appId(): ?string
    {
        return $this->token['appId'] ?? null;
    }

    /**
     * Presigned PUT URL for one file. The URL only accepts a file with exactly
     * this name and size, so the quota checked before this call can't be bypassed.
     *
     * @return array{key: string, url: string}
     */
    public function prepareUpload(string $name, int $size, string $type, string $customId, ?string $acl): array
    {
        $body = array_filter([
            'fileName' => $name,
            'fileSize' => $size,
            'fileType' => $type,
            'customId' => $customId,
            'contentDisposition' => 'inline',
            'acl' => $acl,
        ], fn ($v) => $v !== null && $v !== '');

        $data = $this->post('/v7/prepareUpload', $body);
        if (! is_string($data['key'] ?? null) || ! is_string($data['url'] ?? null)) {
            throw new UploadThingException('UploadThing did not return an upload URL.');
        }

        return ['key' => $data['key'], 'url' => $data['url']];
    }

    /**
     * R-03: server-side upload (task attachments): presign, then PUT the bytes ourselves.
     *
     * @return string the UploadThing file key
     */
    public function uploadFile(string $path, string $name, int $size, string $type, string $customId, ?string $acl): string
    {
        $prepared = $this->prepareUpload($name, $size, $type, $customId, $acl);
        $stream = fopen($path, 'r');
        if ($stream === false) {
            throw new UploadThingException('Could not read the uploaded file.');
        }

        try {
            $response = Http::timeout(120)->attach('file', $stream, $name)->put($prepared['url']);
        } catch (ConnectionException $e) {
            throw new UploadThingException('Could not reach UploadThing. Check the internet connection and try again.', 0, $e);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
        if ($response->failed()) {
            throw new UploadThingException($this->errorMessage($response), $response->status());
        }

        return $prepared['key'];
    }

    /** A short-lived link to read a (private) file. */
    public function fileUrl(string $key, int $expiresIn = 300): string
    {
        $data = $this->post('/v6/requestFileAccess', ['fileKey' => $key, 'expiresIn' => max(1, min($expiresIn, 604800))]);
        $url = $data['ufsUrl'] ?? $data['url'] ?? null;
        if (! is_string($url)) {
            throw new UploadThingException('UploadThing did not return a file link.');
        }

        return $url;
    }

    /**
     * @param  list<string>  $keys
     */
    public function deleteFiles(array $keys): void
    {
        if ($keys !== []) {
            $this->post('/v6/deleteFiles', ['fileKeys' => $keys]);
        }
    }

    /**
     * @return array<string, mixed> appId, defaultACL, allowACLOverride
     */
    public function appInfo(): array
    {
        return $this->post('/v7/getAppInfo', []);
    }

    /**
     * @return array<string, mixed> totalBytes, appTotalBytes, filesUploaded, limitBytes
     */
    public function usage(): array
    {
        return $this->post('/v6/getUsageInfo', []);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        try {
            $response = $this->request()->post($path, (object) $body);
        } catch (ConnectionException $e) {
            throw new UploadThingException('Could not reach UploadThing. Check the internet connection and try again.', 0, $e);
        }

        if ($response->failed()) {
            throw new UploadThingException($this->errorMessage($response), $response->status());
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function request(): PendingRequest
    {
        if ($this->token === null) {
            throw new UploadThingException('UploadThing is not configured. Add UPLOADTHING_TOKEN to .env.');
        }

        return Http::baseUrl($this->apiUrl)
            ->withHeaders([
                'x-uploadthing-api-key' => $this->token['apiKey'],
                'x-uploadthing-version' => '7.7.4',
                'x-uploadthing-be-adapter' => 'overeasy-laravel',
            ])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20);
    }

    private function errorMessage(Response $response): string
    {
        $error = $response->json('error');

        return 'UploadThing error ('.$response->status().'): '.(is_string($error) ? $error : 'request failed');
    }

    /**
     * Confirm the uploaded bytes are really there (HEAD on a signed link).
     *
     * @return array{exists: bool, size: int|null, type: string|null}
     */
    public function stat(string $key): array
    {
        try {
            $response = Http::connectTimeout(5)->timeout(15)->head($this->fileUrl($key, 60));
        } catch (ConnectionException|RequestException|UploadThingException) {
            return ['exists' => false, 'size' => null, 'type' => null];
        }

        if (! $response->successful()) {
            return ['exists' => false, 'size' => null, 'type' => null];
        }

        $length = $response->header('Content-Length');

        return [
            'exists' => true,
            'size' => is_numeric($length) ? (int) $length : null,
            'type' => $response->header('Content-Type') ?: null,
        ];
    }
}
