<?php

namespace App\Domain\Reports;

use App\Models\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Admin view across every active client: workspace health and team workload.
 * Heavy aggregates, cached for 5 minutes (plain arrays only - the Redis cache
 * refuses to unserialize objects).
 *
 * Health rules (documented in README 4f):
 *  overdue  = 5+ overdue tasks, or 25%+ of open tasks overdue
 *  at_risk  = any overdue or blocked task
 *  on_track = otherwise
 */
class AgencyOverview
{
    public const CACHE_SECONDS = 300;

    /**
     * @return array{workspaces: list<array<string, mixed>>, team: list<array<string, mixed>>, totals: array<string, int>, generated_at: string}
     */
    public function get(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget('reports:agency');
        }

        /** @var array{workspaces: list<array<string, mixed>>, team: list<array<string, mixed>>, totals: array<string, int>, generated_at: string} */
        return Cache::remember('reports:agency', self::CACHE_SECONDS, fn (): array => $this->build());
    }

    /**
     * @return array{workspaces: list<array<string, mixed>>, team: list<array<string, mixed>>, totals: array<string, int>, generated_at: string}
     */
    public function build(): array
    {
        $today = now()->toDateString();
        $weekStart = now()->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
        $weekEnd = now()->endOfWeek(CarbonImmutable::SUNDAY)->toDateString();
        $doneIds = TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id')->all();
        $blockedIds = TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_BLOCKED)->pluck('id')->all();

        $workspaces = DB::table('workspaces')
            ->whereNull('deleted_at')
            ->where('status', '!=', 'archived')
            ->orderBy('name')
            ->get(['id', 'name', 'slug', 'color']);

        $open = DB::table('tasks')
            ->whereNull('deleted_at')
            ->whereIn('workspace_id', $workspaces->pluck('id'))
            ->whereNotIn('status_id', $doneIds)
            ->get(['workspace_id', 'assignee_id', 'status_id', 'due_on']);

        $doneWeek = DB::table('tasks')
            ->whereNull('deleted_at')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', now()->subDays(7))
            ->groupBy('workspace_id')
            ->selectRaw('workspace_id, count(*) as n')
            ->pluck('n', 'workspace_id');

        $minutes = DB::table('time_entries')
            ->whereNull('deleted_at')
            ->whereDate('entry_date', '>=', $weekStart)
            ->whereDate('entry_date', '<=', $weekEnd)
            ->where(fn ($q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'));
        $minutesByWorkspace = (clone $minutes)->groupBy('workspace_id')->selectRaw('workspace_id, sum(minutes) as m')->pluck('m', 'workspace_id');
        $minutesByUser = (clone $minutes)->groupBy('user_id')->selectRaw('user_id, sum(minutes) as m')->pluck('m', 'user_id');

        $ws = [];
        $people = [];
        foreach ($open as $t) {
            $wid = (int) $t->workspace_id;
            $ws[$wid] ??= ['open' => 0, 'overdue' => 0, 'blocked' => 0];
            $ws[$wid]['open']++;
            $due = $t->due_on !== null ? substr((string) $t->due_on, 0, 10) : null;
            $isOverdue = $due !== null && $due < $today;
            if ($isOverdue) {
                $ws[$wid]['overdue']++;
            }
            if (in_array($t->status_id, $blockedIds)) {
                $ws[$wid]['blocked']++;
            }

            if ($t->assignee_id !== null) {
                $uid = (int) $t->assignee_id;
                $people[$uid] ??= ['open' => 0, 'overdue' => 0, 'due_week' => 0];
                $people[$uid]['open']++;
                if ($isOverdue) {
                    $people[$uid]['overdue']++;
                } elseif ($due !== null && $due <= $weekEnd) {
                    $people[$uid]['due_week']++;
                }
            }
        }

        $rows = [];
        foreach ($workspaces as $w) {
            $c = $ws[(int) $w->id] ?? ['open' => 0, 'overdue' => 0, 'blocked' => 0];
            $rows[] = [
                'id' => (int) $w->id,
                'name' => (string) $w->name,
                'slug' => (string) $w->slug,
                'color' => (string) $w->color,
                ...$c,
                'done_7d' => (int) ($doneWeek[$w->id] ?? 0),
                'hours_week' => round((int) ($minutesByWorkspace[$w->id] ?? 0) / 60, 1),
                'health' => self::health($c['open'], $c['overdue'], $c['blocked']),
            ];
        }
        $rank = ['overdue' => 0, 'at_risk' => 1, 'on_track' => 2];
        usort($rows, fn (array $a, array $b) => $rank[$a['health']] <=> $rank[$b['health']] ?: strcmp($a['name'], $b['name']));

        $team = [];
        foreach (DB::table('users')->where('is_active', true)->orderBy('name')->get(['id', 'name', 'title']) as $u) {
            $p = $people[(int) $u->id] ?? ['open' => 0, 'overdue' => 0, 'due_week' => 0];
            $team[] = [
                'id' => (int) $u->id,
                'name' => (string) $u->name,
                'title' => $u->title,
                ...$p,
                'hours_week' => round((int) ($minutesByUser[$u->id] ?? 0) / 60, 1),
            ];
        }

        return [
            'workspaces' => $rows,
            'team' => $team,
            'totals' => [
                'workspaces' => count($rows),
                'on_track' => count(array_filter($rows, fn ($r) => $r['health'] === 'on_track')),
                'at_risk' => count(array_filter($rows, fn ($r) => $r['health'] === 'at_risk')),
                'overdue' => count(array_filter($rows, fn ($r) => $r['health'] === 'overdue')),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public static function health(int $open, int $overdue, int $blocked): string
    {
        if ($overdue >= 5 || ($open > 0 && $overdue / $open >= 0.25)) {
            return 'overdue';
        }

        return $overdue > 0 || $blocked > 0 ? 'at_risk' : 'on_track';
    }
}
