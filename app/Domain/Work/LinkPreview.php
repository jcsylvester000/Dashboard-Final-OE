<?php

namespace App\Domain\Work;

use Closure;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * E-29: read a pasted link's page title and site name, safely. Only http(s); the host must
 * resolve to public addresses (no internal network, no localhost); 3-second timeout; up to
 * 3 redirects, each checked again; only the first 512 KB is read. If anything fails, the
 * link is still kept, just without a title.
 */
class LinkPreview
{
    public const TIMEOUT_SECONDS = 3;

    private const MAX_BYTES = 524288;

    /** @var (Closure(string): list<string>)|null test hook: host -> IP addresses */
    public static ?Closure $resolver = null;

    /**
     * @return array{title: string|null, site: string|null}
     */
    public function fetch(string $url): array
    {
        $site = self::host($url);
        try {
            for ($hop = 0; $hop <= 3; $hop++) {
                if (! $this->allowed($url)) {
                    return ['title' => null, 'site' => $site];
                }
                $response = Http::timeout(self::TIMEOUT_SECONDS)->connectTimeout(self::TIMEOUT_SECONDS)
                    ->withOptions(['allow_redirects' => false])
                    ->withHeaders(['User-Agent' => 'OverEasyDashboard/1.0 (link preview)', 'Accept' => 'text/html'])
                    ->get($url);
                if ($response->redirect()) {
                    $next = (string) $response->header('Location');
                    if ($next === '') {
                        break;
                    }
                    $url = str_starts_with($next, '/') ? (parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).$next) : $next;

                    continue;
                }
                if (! $response->successful()) {
                    break;
                }
                $html = substr($response->body(), 0, self::MAX_BYTES);

                return ['title' => self::title($html), 'site' => self::meta($html, 'og:site_name') ?? $site];
            }
        } catch (Throwable) {
            // Unreachable or slow site: keep the link without a title.
        }

        return ['title' => null, 'site' => $site];
    }

    public static function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? preg_replace('/^www\./', '', strtolower($host)) : null;
    }

    private function allowed(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (! in_array($scheme, ['http', 'https'], true) || ! is_string($host) || $host === '') {
            return false;
        }
        $ips = match (true) {
            filter_var($host, FILTER_VALIDATE_IP) !== false => [$host], // a literal IP is checked as written
            self::$resolver !== null => (self::$resolver)($host),
            default => gethostbynamel($host) ?: [],
        };
        if ($ips === []) {
            return false;
        }
        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }

    private static function title(string $html): ?string
    {
        $title = self::meta($html, 'og:title');
        if ($title === null && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) === 1) {
            $title = $m[1];
        }

        return self::clean($title);
    }

    private static function meta(string $html, string $property): ?string
    {
        $p = preg_quote($property, '/');
        if (preg_match('/<meta[^>]+(?:property|name)=["\']'.$p.'["\'][^>]*content=["\']([^"\']*)["\']/i', $html, $m) === 1
            || preg_match('/<meta[^>]+content=["\']([^"\']*)["\'][^>]*(?:property|name)=["\']'.$p.'["\']/i', $html, $m) === 1) {
            return self::clean($m[1]);
        }

        return null;
    }

    private static function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return $text === '' ? null : mb_substr($text, 0, 300);
    }
}
