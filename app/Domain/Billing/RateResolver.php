<?php

namespace App\Domain\Billing;

use App\Models\BillingProfile;
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
 * Only cards in the client's billing currency count (E-100): a USD client never
 * falls back to peso rates; with no USD card the rate is missing, not wrong.
 */
class RateResolver
{
    /** @var array<int, Collection<int, RateCard>> cards per workspace id */
    private array $cache = [];

    /** @var array<int, string> billing currency per workspace id */
    private array $currencies = [];

    public function resolve(int $workspaceId, ?int $departmentId, ?int $userId, string $date, ?string $currency = null): ?RateCard
    {
        $currency ??= $this->currencyOf($workspaceId);
        $effective = $this->cards($workspaceId)
            ->filter(fn (RateCard $c) => $c->currency === $currency && $c->effective_from->toDateString() <= $date);

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

    /** The client's billing currency (PHP when it has no billing profile yet). */
    public function currencyOf(int $workspaceId): string
    {
        return $this->currencies[$workspaceId] ??= (string) (BillingProfile::query()->where('workspace_id', $workspaceId)->value('currency') ?? 'PHP');
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
