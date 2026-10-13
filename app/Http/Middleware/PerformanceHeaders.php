<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * E-130: per-request timing for measuring and tuning. Adds a standard Server-Timing
 * header (visible in the browser's Network panel) and X-Query-Count. On when
 * APP_DEBUG is true or PERF_HEADERS=true; never exposes SQL text.
 */
class PerformanceHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.debug') && ! config('app.perf_headers')) {
            return $next($request);
        }

        $queries = 0;
        $dbMs = 0.0;
        DB::listen(function (QueryExecuted $q) use (&$queries, &$dbMs): void {
            $queries++;
            $dbMs += $q->time;
        });
        $start = defined('LARAVEL_START') ? (float) LARAVEL_START : microtime(true);

        $response = $next($request);

        $total = (microtime(true) - $start) * 1000;
        $response->headers->set('Server-Timing', sprintf('app;dur=%.1f, db;dur=%.1f;desc="%d queries"', $total, $dbMs, $queries));
        $response->headers->set('X-Query-Count', (string) $queries);

        return $response;
    }
}
