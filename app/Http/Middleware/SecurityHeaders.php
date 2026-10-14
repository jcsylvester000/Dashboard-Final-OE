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
        // same-origin: other sites never see our URLs, but our own pages send a Referer so
        // back() returns to the right page after Inertia (XHR) navigation. Access-link pages
        // and public share links carry a token in the URL, so they send no Referer at all.
        $headers->set('Referrer-Policy', $request->is('access/*', 's/*', 'p/*', 'i/*', 'st/*') ? 'no-referrer' : 'same-origin');
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
        // UploadThing: the browser uploads straight to the ingest servers and shows
        // images from signed ufs.sh links.
        $connect = ["'self'", 'https://*.ingest.uploadthing.com'];
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
            "img-src 'self' data: blob: https://*.ufs.sh https://utfs.io",
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
