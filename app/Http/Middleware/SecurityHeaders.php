<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline security headers for an internal app, plus a Content-Security-Policy
 * when CSP_ENABLED (default: on in production, off locally where the Vite dev
 * server is used). Scripts need the per-request nonce; styles allow inline
 * attributes (Vue :style). Add the map tile origin here when the map is built.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $csp = (bool) config('app.csp_enabled');
        if ($csp) {
            Vite::useCspNonce();
        }

        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Frame-Options', 'DENY');
        $headers->set('X-Content-Type-Options', 'nosniff');
        // no-referrer keeps one-time access-link tokens out of Referer headers.
        $headers->set('Referrer-Policy', 'no-referrer');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $headers->set('X-Robots-Tag', 'noindex, nofollow');

        if ($csp) {
            $headers->set('Content-Security-Policy', $this->policy((string) Vite::cspNonce()));
        }

        if ($request->isSecure() && app()->isProduction()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        $connect = ["'self'"];
        if (config('broadcasting.default') === 'reverb') {
            $host = (string) config('broadcasting.connections.reverb.options.host', '');
            if ($host !== '') {
                $connect[] = 'wss://'.$host;
                $connect[] = 'ws://'.$host;
            }
        }

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob:",
            "font-src 'self' data:",
            'connect-src '.implode(' ', $connect),
            "frame-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }
}
