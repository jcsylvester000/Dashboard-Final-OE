<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * API POSTs may send an `Idempotency-Key` header. The first successful (2xx) response
 * is stored for 24 hours per user; repeating the same key returns that response
 * instead of doing the work twice (e.g. a mobile app retrying on a flaky network).
 * Reusing a key for a different endpoint is refused with 422.
 */
class Idempotency
{
    public const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);
        $user = $request->user();

        if (! $request->isMethod('POST') || ! is_string($key) || $key === '' || $user === null) {
            return $next($request);
        }

        if (strlen($key) > 100) {
            return new JsonResponse(['message' => 'Idempotency-Key must be 100 characters or fewer.'], 422);
        }

        $existing = DB::table('api_idempotency_keys')
            ->where('user_id', $user->getAuthIdentifier())
            ->where('key', $key)
            ->where('created_at', '>=', now()->subDay())
            ->first();

        if ($existing !== null) {
            if ($existing->path !== $request->path()) {
                return new JsonResponse(['message' => 'This Idempotency-Key was already used for a different request.'], 422);
            }

            // Replay the stored bytes as-is (re-encoding would turn {} into []).
            return JsonResponse::fromJsonString((string) $existing->body, (int) $existing->status, ['Idempotent-Replayed' => 'true']);
        }

        $response = $next($request);

        if ($response->isSuccessful() && $response instanceof JsonResponse) {
            DB::table('api_idempotency_keys')->where('user_id', $user->getAuthIdentifier())->where('key', $key)->delete();
            DB::table('api_idempotency_keys')->insert([
                'user_id' => $user->getAuthIdentifier(),
                'key' => $key,
                'method' => 'POST',
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'body' => (string) $response->getContent(),
                'created_at' => now(),
            ]);
        }

        return $response;
    }
}
