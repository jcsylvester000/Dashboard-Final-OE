<?php

namespace App\Domain\Files\Video;

use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Minimal S3-compatible client (AWS Signature Version 4) for the calls video needs:
 * presigned URLs (GET / PUT part), multipart create / complete / abort, put a file,
 * head and delete. Works with Cloudflare R2, AWS S3, Wasabi and Backblaze B2.
 * No SDK dependency; the signer is checked against the AWS documented test vector.
 */
class S3Client
{
    private const ALGO = 'AWS4-HMAC-SHA256';

    public function __construct(
        private readonly string $endpoint,
        private readonly string $region,
        private readonly string $bucket,
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly bool $pathStyle = true,
    ) {}

    /**
     * A presigned URL (query-string auth, UNSIGNED-PAYLOAD).
     *
     * @param  array<string, string>  $query  extra query parameters (partNumber, uploadId, response-*)
     */
    public function presign(string $method, string $key, int $expiresSeconds, array $query = [], ?int $now = null): string
    {
        $time = $now ?? now()->getTimestamp();
        $date = gmdate('Ymd\THis\Z', $time);
        $scope = substr($date, 0, 8).'/'.$this->region.'/s3/aws4_request';
        [$host, $path] = $this->target($key);

        $query += [
            'X-Amz-Algorithm' => self::ALGO,
            'X-Amz-Credential' => $this->accessKey.'/'.$scope,
            'X-Amz-Date' => $date,
            'X-Amz-Expires' => (string) $expiresSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];
        $canonicalQuery = self::canonicalQuery($query);
        $canonical = implode("\n", [strtoupper($method), $path, $canonicalQuery, 'host:'.$host, '', 'host', 'UNSIGNED-PAYLOAD']);
        $signature = $this->signature($date, $scope, $canonical);

        return $this->scheme().'://'.$host.$path.'?'.$canonicalQuery.'&X-Amz-Signature='.$signature;
    }

    /** Start a multipart upload and return its upload id. */
    public function createMultipart(string $key, string $contentType): string
    {
        $response = $this->send('POST', $key, ['uploads' => ''], '', ['content-type' => $contentType]);
        $id = self::xmlValue($response->body(), 'UploadId');
        if ($id === null) {
            throw new RuntimeException('Storage did not return an upload id.');
        }

        return $id;
    }

    /**
     * @param  list<array{part: int, etag: string}>  $parts
     */
    public function completeMultipart(string $key, string $uploadId, array $parts): void
    {
        usort($parts, fn (array $a, array $b) => $a['part'] <=> $b['part']);
        $xml = '<CompleteMultipartUpload>';
        foreach ($parts as $p) {
            $etag = '"'.trim($p['etag'], '"').'"';
            $xml .= '<Part><PartNumber>'.$p['part'].'</PartNumber><ETag>'.htmlspecialchars($etag, ENT_XML1).'</ETag></Part>';
        }
        $xml .= '</CompleteMultipartUpload>';
        $response = $this->send('POST', $key, ['uploadId' => $uploadId], $xml, ['content-type' => 'application/xml']);
        // S3 can answer 200 with an error document.
        if (str_contains($response->body(), '<Error>')) {
            throw new RuntimeException('Storage refused to complete the upload: '.(self::xmlValue($response->body(), 'Message') ?? 'unknown error'));
        }
    }

    public function abortMultipart(string $key, string $uploadId): void
    {
        $this->send('DELETE', $key, ['uploadId' => $uploadId], '', [], allow404: true);
    }

    /** Upload a local file (proxy, poster) streamed from disk. */
    public function putFile(string $key, string $path, string $contentType): void
    {
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new RuntimeException('Cannot read '.basename($path));
        }
        try {
            $this->send('PUT', $key, [], $stream, ['content-type' => $contentType]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** Download an object to a local file (for ffmpeg). */
    public function getToFile(string $key, string $path, int $timeoutSeconds = 3600): void
    {
        Http::timeout($timeoutSeconds)->withOptions(['sink' => $path])->get($this->presign('GET', $key, 900))->throw();
    }

    /** Size in bytes, or null when the object does not exist. */
    public function size(string $key): ?int
    {
        $response = $this->send('HEAD', $key, [], '', [], allow404: true);

        return $response->status() === 404 ? null : (int) $response->header('Content-Length');
    }

    public function delete(string $key): void
    {
        $this->send('DELETE', $key, [], '', [], allow404: true);
    }

    /**
     * A header-signed request.
     *
     * @param  array<string, string>  $query
     * @param  string|resource  $body
     * @param  array<string, string>  $headers  lower-case names
     */
    private function send(string $method, string $key, array $query, mixed $body, array $headers, bool $allow404 = false): Response
    {
        $date = gmdate('Ymd\THis\Z', now()->getTimestamp());
        $scope = substr($date, 0, 8).'/'.$this->region.'/s3/aws4_request';
        [$host, $path] = $this->target($key);
        $payloadHash = is_string($body) ? hash('sha256', $body) : 'UNSIGNED-PAYLOAD';

        $signed = ['host' => $host, 'x-amz-content-sha256' => $payloadHash, 'x-amz-date' => $date];
        ksort($signed);
        $canonicalHeaders = '';
        foreach ($signed as $name => $value) {
            $canonicalHeaders .= $name.':'.trim($value)."\n";
        }
        $signedNames = implode(';', array_keys($signed));
        $canonicalQuery = self::canonicalQuery($query);
        $canonical = implode("\n", [$method, $path, $canonicalQuery, $canonicalHeaders, $signedNames, $payloadHash]);
        $authorization = self::ALGO.' Credential='.$this->accessKey.'/'.$scope.', SignedHeaders='.$signedNames.', Signature='.$this->signature($date, $scope, $canonical);

        $url = $this->scheme().'://'.$host.$path.($canonicalQuery !== '' ? '?'.$canonicalQuery : '');
        $request = Http::timeout(120)->withHeaders([
            ...array_diff_key($headers, ['content-type' => true]),
            'x-amz-content-sha256' => $payloadHash,
            'x-amz-date' => $date,
            'Authorization' => $authorization,
        ]);
        if (! is_string($body) || $body !== '') {
            $request = $request->withBody(is_string($body) ? $body : Utils::streamFor($body), $headers['content-type'] ?? 'application/octet-stream');
        } elseif (isset($headers['content-type'])) {
            $request = $request->contentType($headers['content-type']);
        }

        $response = $request->send($method, $url);
        if ($response->status() === 404 && $allow404) {
            return $response;
        }
        if (! $response->successful()) {
            throw new RuntimeException('Storage request failed ('.$response->status().'): '.(self::xmlValue($response->body(), 'Code') ?? 'no detail'));
        }

        return $response;
    }

    /**
     * @return array{0: string, 1: string} host and canonical path
     */
    private function target(string $key): array
    {
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);
        $port = parse_url($this->endpoint, PHP_URL_PORT);
        if (is_int($port)) {
            $host .= ':'.$port;
        }
        $encodedKey = implode('/', array_map('rawurlencode', explode('/', ltrim($key, '/'))));

        return $this->pathStyle
            ? [$host, '/'.rawurlencode($this->bucket).'/'.$encodedKey]
            : [$this->bucket.'.'.$host, '/'.$encodedKey];
    }

    private function scheme(): string
    {
        return parse_url($this->endpoint, PHP_URL_SCHEME) === 'http' ? 'http' : 'https';
    }

    private function signature(string $date, string $scope, string $canonical): string
    {
        $toSign = implode("\n", [self::ALGO, $date, $scope, hash('sha256', $canonical)]);
        $key = hash_hmac('sha256', substr($date, 0, 8), 'AWS4'.$this->secretKey, true);
        $key = hash_hmac('sha256', $this->region, $key, true);
        $key = hash_hmac('sha256', 's3', $key, true);
        $key = hash_hmac('sha256', 'aws4_request', $key, true);

        return hash_hmac('sha256', $toSign, $key);
    }

    /**
     * @param  array<string, string>  $query
     */
    private static function canonicalQuery(array $query): string
    {
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[rawurlencode((string) $name)] = rawurlencode($value);
        }
        ksort($pairs, SORT_STRING);
        $out = [];
        foreach ($pairs as $name => $value) {
            $out[] = $name.'='.$value;
        }

        return implode('&', $out);
    }

    private static function xmlValue(string $xml, string $tag): ?string
    {
        return preg_match('/<'.$tag.'>([^<]*)<\/'.$tag.'>/', $xml, $m) === 1 ? html_entity_decode($m[1], ENT_XML1) : null;
    }
}
