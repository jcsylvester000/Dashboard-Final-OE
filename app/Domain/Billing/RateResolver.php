<?php

namespace App\Domain\Billing;

use App\Models\RateCard;
use Illuminate\Support\Collection;

/**
 * Finds the hourly rate for a piece of work. Most specific card wins:
 *   1. this workspace + this person
 *   2. this workspace + this department
 *   3. this workspace default (no person, no department)
 *   4. agency-wide person
 *   5. agency-wide department
 *   6. agency-wide default
 * Within a level, the latest card effective on or before the work date applies.
 */
class RateResolver
{
    /** @var array<int, Collection<int, RateCard>> cards per workspace id */
    private array $cache = [];

    public function resolve(int $workspaceId, ?int $departmentId, ?int $userId, string $date): ?RateCard
    {
        $effective = $this->cards($workspaceId)
            ->filter(fn (RateCard $c) => $c->effective_from->toDateString() <= $date);

        $levels = [];
        foreach ([$workspaceId, null] as $ws) {
            if ($userId !== null) {
                $levels[] = [$ws, null, $userId];
            }
            if ($departmentId !== null) {
                $levels[] = [$ws, $departmentId, null];
            }
            $levels[] = [$ws, null, null];
        }

        foreach ($levels as [$ws, $dept, $user]) {
            $match = $effective->first(fn (RateCard $c) => $c->workspace_id === $ws
                && $c->department_id === $dept
                && $c->user_id === $user);

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Agency-wide cards plus this workspace's, newest first (loaded once per workspace).
     *
     * @return Collection<int, RateCard>
     */
    private function cards(int $workspaceId): Collection
    {
        return $this->cache[$workspaceId] ??= RateCard::query()
            ->where(fn ($q) => $q->whereNull('workspace_id')->orWhere('workspace_id', $workspaceId))
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->get();
    }
}
