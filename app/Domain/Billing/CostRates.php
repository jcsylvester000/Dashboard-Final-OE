<?php

namespace App\Domain\Billing;

use App\Domain\Identity\ActivityLogger;
use App\Models\CostRate;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * E-77: internal cost per person (PHP per hour, effective-dated), kept apart from the bill
 * rates clients see and visible to Finance only. The latest rate effective on the work date
 * wins; people without one fall back to the cost on their rate card.
 */
class CostRates
{
    /** @var array<int, Collection<int, CostRate>> */
    private array $cache = [];

    public function __construct(private readonly ActivityLogger $activity) {}

    /** PHP minor units per hour for this person on this date, or null when none is set. */
    public function perHour(?int $userId, string $date): ?int
    {
        if ($userId === null) {
            return null;
        }
        $this->cache[$userId] ??= CostRate::query()->where('user_id', $userId)->orderByDesc('effective_from')->get();
        $rate = $this->cache[$userId]->first(fn (CostRate $r) => $r->effective_from->toDateString() <= $date);

        return $rate?->cost_minor;
    }

    public function set(User $person, int $costMinor, string $from, ?string $note, User $by): CostRate
    {
        $existing = CostRate::query()->where('user_id', $person->id)->whereDate('effective_from', $from)->first();
        $before = $existing?->cost_minor;
        // whereDate above, not updateOrCreate: date columns may hold a time part on some databases.
        $rate = $existing ?? new CostRate(['user_id' => $person->id, 'effective_from' => $from]);
        $rate->fill(['cost_minor' => $costMinor, 'note' => $note, 'created_by' => $by->id])->save();
        unset($this->cache[$person->id]);
        // Amounts only in the finance audit row (activity log is admin/finance only).
        $this->activity->log('billing.cost-rate-set', $person, ['from' => $from, 'before_minor' => $before, 'after_minor' => $costMinor], $by);

        return $rate;
    }
}
