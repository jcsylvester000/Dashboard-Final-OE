<?php

namespace App\Domain\Identity;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * E-88: signed-in devices and sign-in history. Works with any session driver: ending a
 * session destroys it in the store (Redis, database or file) and marks the row, and
 * TrackUserSession signs out any request still carrying a revoked session.
 */
class SessionTracker
{
    /** Devices not seen for this long drop off the list. */
    public const SHOW_DAYS = 30;

    /** Failed sign-ins this close together are shown as a burst. */
    public const BURST_COUNT = 5;

    public const BURST_MINUTES = 15;

    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * Record this request's session. False when the session was ended elsewhere.
     */
    public function touch(Request $request, User $user): bool
    {
        if (! $request->hasSession()) {
            return true;
        }
        $id = $request->session()->getId();
        $row = UserSession::query()->where('session_id', $id)->first();

        if ($row !== null && ($row->revoked_at !== null || $row->user_id !== $user->id)) {
            return false;
        }
        // Write at most every 5 minutes per session.
        if ($row !== null && Cache::has('user-session:'.$id)) {
            return true;
        }
        if ($row === null) {
            UserSession::create([
                'user_id' => $user->id,
                'session_id' => $id,
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'last_seen_at' => now(),
            ]);
        } else {
            $row->forceFill(['last_seen_at' => now(), 'ip_address' => $request->ip()])->save();
        }
        Cache::put('user-session:'.$id, true, now()->addMinutes(5));

        return true;
    }

    /**
     * @return list<array{id: int, browser: string, ip: string|null, last_seen_at: string, created_at: string, current: bool}>
     */
    public function devices(User $user, ?string $currentSessionId = null): array
    {
        return UserSession::query()
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('last_seen_at', '>=', now()->subDays(self::SHOW_DAYS))
            ->latest('last_seen_at')
            ->limit(20)
            ->get()
            ->map(fn (UserSession $s): array => [
                'id' => $s->id,
                'browser' => self::describe($s->user_agent),
                'ip' => $s->ip_address,
                'last_seen_at' => $s->last_seen_at->toIso8601String(),
                'created_at' => $s->created_at->toIso8601String(),
                'current' => $currentSessionId !== null && hash_equals($s->session_id, $currentSessionId),
            ])
            ->values()
            ->all();
    }

    public function revoke(UserSession $session, ?User $by): void
    {
        $this->destroy($session->session_id);
        $session->forceFill(['revoked_at' => now()])->save();
        $this->activity->log('user.session-revoked', $session->user, ['browser' => self::describe($session->user_agent), 'ip' => $session->ip_address], $by);
    }

    /** End every session of this person except, optionally, the one in use. */
    public function revokeAll(User $user, ?string $exceptSessionId = null): int
    {
        $rows = UserSession::query()->where('user_id', $user->id)->whereNull('revoked_at')
            ->when($exceptSessionId !== null, fn ($q) => $q->where('session_id', '!=', $exceptSessionId))
            ->get();
        foreach ($rows as $row) {
            $this->destroy($row->session_id);
            $row->forceFill(['revoked_at' => now()])->save();
        }

        return $rows->count();
    }

    /**
     * Last sign-ins and failed attempts (by the account's email), newest first.
     *
     * @return list<array{at: string, ok: bool, ip: string|null, browser: string, burst: bool}>
     */
    public function history(User $user, int $limit = 30): array
    {
        $rows = ActivityLog::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('action', 'auth.login')->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id))
                ->orWhere(fn ($q) => $q->where('action', 'auth.failed')->where('properties->email', mb_strtolower($user->email))))
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'action', 'ip_address', 'user_agent', 'created_at']);

        $failures = $rows->where('action', 'auth.failed')->pluck('created_at')->map(fn ($t) => $t->getTimestamp())->values()->all();

        return $rows->map(function (ActivityLog $r) use ($failures): array {
            $at = $r->created_at->getTimestamp();
            $near = count(array_filter($failures, fn (int $t) => abs($t - $at) <= self::BURST_MINUTES * 60));

            return [
                'at' => $r->created_at->toIso8601String(),
                'ok' => $r->action === 'auth.login',
                'ip' => $r->ip_address,
                'browser' => self::describe($r->user_agent),
                'burst' => $r->action === 'auth.failed' && $near >= self::BURST_COUNT,
            ];
        })->values()->all();
    }

    private function destroy(string $sessionId): void
    {
        app('session')->driver()->getHandler()->destroy($sessionId);
        DB::table('sessions')->where('id', $sessionId)->delete(); // database driver leftovers
    }

    /** "Chrome on Windows" from a user agent, without a parsing library. */
    public static function describe(?string $agent): string
    {
        $ua = (string) $agent;
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            $ua === '' => 'Unknown browser',
            default => 'Other browser',
        };
        $os = match (true) {
            str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') => 'macOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => null,
        };

        return $os !== null ? "{$browser} on {$os}" : $browser;
    }
}
