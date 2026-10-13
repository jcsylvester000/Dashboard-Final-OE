<?php

namespace App\Domain\Billing;

use App\Domain\Identity\ActivityLogger;
use App\Models\BillingProfile;
use App\Models\RateCard;
use App\Models\RateTemplate;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Rate templates: per-person hourly rates kept as a reusable set. Applying one to
 * a client copies its rows into that client's own rate cards (effective-dated), so
 * the client's rates can then change without touching the template or other clients.
 */
class RateTemplateService
{
    public function __construct(
        private readonly RateResolver $rates,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * Replace a template's rows. Each row: user_id OR department_id OR neither
     * (template default), with a rate and optional internal cost.
     *
     * @param  list<array{user_id?: int|null, department_id?: int|null, rate: string, cost?: string|null}>  $items
     */
    public function saveItems(RateTemplate $template, array $items): void
    {
        DB::transaction(function () use ($template, $items) {
            $template->items()->delete();
            $seen = [];
            foreach ($items as $item) {
                $rate = trim((string) $item['rate']);
                if ($rate === '') {
                    continue; // blank row = no rate for this person in the template
                }
                $userId = isset($item['user_id']) ? (int) $item['user_id'] : null;
                $deptId = $userId === null && isset($item['department_id']) ? (int) $item['department_id'] : null;
                $key = ($userId ?? 0).':'.($deptId ?? 0);
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $cost = trim((string) ($item['cost'] ?? ''));

                $template->items()->create([
                    'user_id' => $userId,
                    'department_id' => $deptId,
                    'rate_minor' => Money::parse($rate),
                    'cost_minor' => $cost !== '' ? Money::parse($cost) : null,
                ]);
            }
        });
    }

    /**
     * Start a template from the current agency-wide rates (people, departments, default).
     */
    public function copyAgencyRates(RateTemplate $template): void
    {
        $latest = RateCard::query()->whereNull('workspace_id')
            ->where('effective_from', '<=', now()->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('id')->get()
            ->unique(fn (RateCard $c) => ($c->user_id ?? 0).':'.($c->department_id ?? 0));

        $this->saveItems($template, $latest->map(fn (RateCard $c) => [
            'user_id' => $c->user_id,
            'department_id' => $c->department_id,
            'rate' => Money::toDecimal($c->rate_minor),
            'cost' => $c->cost_minor !== null ? Money::toDecimal($c->cost_minor) : null,
        ])->values()->all());
    }

    /**
     * Copy the template into the client's own rate cards from $effectiveFrom.
     * People not on the client are skipped.
     *
     * @return array{created: int, skipped: list<string>}
     */
    public function apply(RateTemplate $template, Workspace $workspace, string $effectiveFrom, User $actor): array
    {
        $template->loadMissing('items.user:id,name');
        $memberIds = $workspace->members()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $created = 0;
        $skipped = [];

        DB::transaction(function () use ($template, $workspace, $effectiveFrom, $actor, $memberIds, &$created, &$skipped) {
            foreach ($template->items as $item) {
                if ($item->user_id !== null && ! in_array($item->user_id, $memberIds, true)) {
                    $skipped[] = $item->user->name ?? ('#'.$item->user_id);

                    continue;
                }
                $card = RateCard::create([
                    'workspace_id' => $workspace->id,
                    'user_id' => $item->user_id,
                    'department_id' => $item->department_id,
                    'rate_minor' => $item->rate_minor,
                    'cost_minor' => $item->cost_minor,
                    'effective_from' => $effectiveFrom,
                    'rate_template_id' => $template->id,
                ]);
                app(RateAudit::class)->cardAdded($card, $actor, $template);
                $created++;
            }

            $profile = BillingProfile::query()->firstOrCreate(['workspace_id' => $workspace->id]);
            $profile->forceFill(['rate_template_id' => $template->id, 'rate_template_applied_at' => now()])->save();

            $this->activity->log('billing.rate-template-applied', $workspace, [
                'template' => $template->name, 'effective_from' => $effectiveFrom, 'created' => $created,
            ], $actor);
        });

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Every member of the client with the rate that applies today and where it comes from.
     *
     * @return list<array{id: int, name: string, title: string|null, department: string|null, rate_minor: int|null, cost_minor: int|null, source: string, effective_from: string|null}>
     */
    public function teamRates(Workspace $workspace): array
    {
        $today = now()->toDateString();

        return $workspace->members()->with('primaryDepartment:id,name')->where('users.is_active', true)->orderBy('name')
            ->get(['users.id', 'users.name', 'users.title', 'users.primary_department_id'])
            ->map(function (User $u) use ($workspace, $today) {
                $card = $this->rates->resolve($workspace->id, $u->primary_department_id, $u->id, $today);

                return [
                    'id' => $u->id,
                    'name' => $u->name,
                    'title' => $u->title,
                    'department' => $u->primaryDepartment?->name,
                    'rate_minor' => $card?->rate_minor,
                    'cost_minor' => $card?->cost_minor,
                    'source' => $card === null ? 'none' : self::sourceOf($card, $u),
                    'effective_from' => $card?->effective_from->toDateString(),
                ];
            })->values()->all();
    }

    /** "client-person", "client-department", "client-default", "agency-person", ... */
    private static function sourceOf(RateCard $card, User $u): string
    {
        $scope = $card->workspace_id !== null ? 'client' : 'agency';
        $level = match (true) {
            $card->user_id === $u->id => 'person',
            $card->department_id !== null => 'department',
            default => 'default',
        };

        return $scope.'-'.$level;
    }
}
