<?php

namespace App\Domain\Billing;

use App\Domain\Identity\ActivityLogger;
use App\Models\Department;
use App\Models\RateCard;
use App\Models\RateTemplate;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;

/**
 * Field-level audit of rate changes (brutal scenario H5: "who changed Aisha's rate and when?").
 * Each change is one activity_log row. The subject is the person (or department) the rate is for,
 * so a person's history is one query. Properties hold from/to amounts in centavos.
 */
class RateAudit
{
    public const ACTIONS = ['rate.set', 'rate.removed', 'rate.template-changed'];

    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly RateResolver $resolver,
    ) {}

    /** A new effective-dated card (agency, client, or from a template). */
    public function cardAdded(RateCard $card, ?User $actor = null, ?RateTemplate $via = null): void
    {
        $previous = RateCard::query()
            ->where('workspace_id', $card->workspace_id)
            ->where('department_id', $card->department_id)
            ->where('user_id', $card->user_id)
            ->where('currency', $card->currency)
            ->whereKeyNot($card->id)
            ->where('effective_from', '<=', $card->effective_from->toDateString())
            ->orderByDesc('effective_from')->orderByDesc('id')
            ->first();

        // First personal rate: "from" is whatever applied before (department, client or agency default).
        $fromNote = null;
        if ($previous === null && $card->user_id !== null) {
            $deptId = User::query()->whereKey($card->user_id)->value('primary_department_id');
            $previous = $this->resolver->resolve((int) $card->workspace_id, $deptId !== null ? (int) $deptId : null, $card->user_id, $card->effective_from->copy()->subDay()->toDateString(), $card->currency);
            $fromNote = $previous === null ? null : match (true) {
                $previous->user_id !== null => $previous->workspace_id !== null ? 'client rate' : 'agency rate',
                $previous->department_id !== null => 'department rate',
                $previous->workspace_id !== null => 'client default',
                default => 'agency default',
            };
        }

        $this->activity->log('rate.set', $this->subject($card->user_id, $card->department_id), [
            ...$this->scope($card->workspace_id),
            'user_id' => $card->user_id,
            'department_id' => $card->department_id,
            'from_minor' => $previous?->rate_minor,
            'from_note' => $fromNote,
            'to_minor' => $card->rate_minor,
            'cost_from_minor' => $previous?->cost_minor,
            'cost_to_minor' => $card->cost_minor,
            'effective_from' => $card->effective_from->toDateString(),
            'currency' => $card->currency,
            'via' => $via?->name,
        ], $actor);
    }

    /** E-65: a card corrected in place (amount, cost or date), with the reason. */
    public function cardEdited(RateCard $card, int $fromRate, ?int $fromCost, string $fromDate, string $reason, ?User $actor = null): void
    {
        $this->activity->log('rate.set', $this->subject($card->user_id, $card->department_id), [
            ...$this->scope($card->workspace_id),
            'user_id' => $card->user_id,
            'department_id' => $card->department_id,
            'from_minor' => $fromRate,
            'from_note' => 'corrected',
            'to_minor' => $card->rate_minor,
            'cost_from_minor' => $fromCost,
            'cost_to_minor' => $card->cost_minor,
            'effective_from' => $card->effective_from->toDateString(),
            'effective_from_was' => $fromDate,
            'currency' => $card->currency,
            'reason' => $reason,
        ], $actor);
    }

    public function cardRemoved(RateCard $card, ?User $actor = null): void
    {
        $this->activity->log('rate.removed', $this->subject($card->user_id, $card->department_id), [
            ...$this->scope($card->workspace_id),
            'user_id' => $card->user_id,
            'department_id' => $card->department_id,
            'from_minor' => $card->rate_minor,
            'to_minor' => null,
            'cost_from_minor' => $card->cost_minor,
            'cost_to_minor' => null,
            'effective_from' => $card->effective_from->toDateString(),
        ], $actor);
    }

    /**
     * Compare a template's rows before and after a save; log only rows that changed.
     *
     * @param  array<string, array{rate: int, cost: int|null}>  $before  key "user:dept" (0 = none)
     * @param  array<string, array{rate: int, cost: int|null}>  $after
     */
    public function templateChanged(RateTemplate $template, array $before, array $after, ?User $actor = null): int
    {
        $logged = 0;
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = $before[$key] ?? null;
            $new = $after[$key] ?? null;
            if ($old === $new) {
                continue;
            }
            [$userId, $deptId] = array_map('intval', explode(':', $key));

            $this->activity->log('rate.template-changed', $this->subject($userId ?: null, $deptId ?: null), [
                'scope' => 'template',
                'scope_label' => $template->name,
                'template_id' => $template->id,
                'user_id' => $userId ?: null,
                'department_id' => $deptId ?: null,
                'from_minor' => $old['rate'] ?? null,
                'to_minor' => $new['rate'] ?? null,
                'cost_from_minor' => $old['cost'] ?? null,
                'cost_to_minor' => $new['cost'] ?? null,
            ], $actor);
            $logged++;
        }

        return $logged;
    }

    /**
     * @return array<string, array{rate: int, cost: int|null}>
     */
    public static function snapshot(RateTemplate $template): array
    {
        $out = [];
        foreach ($template->items()->get() as $item) {
            $out[($item->user_id ?? 0).':'.($item->department_id ?? 0)] = ['rate' => $item->rate_minor, 'cost' => $item->cost_minor];
        }

        return $out;
    }

    private function subject(?int $userId, ?int $departmentId): ?Model
    {
        if ($userId !== null) {
            return User::query()->find($userId);
        }

        return $departmentId !== null ? Department::query()->find($departmentId) : null;
    }

    /**
     * @return array{scope: string, scope_label: string, workspace_id: int|null}
     */
    private function scope(?int $workspaceId): array
    {
        if ($workspaceId === null) {
            return ['scope' => 'agency', 'scope_label' => 'Agency rates', 'workspace_id' => null];
        }

        return ['scope' => 'client', 'scope_label' => (string) Workspace::query()->whereKey($workspaceId)->value('name'), 'workspace_id' => $workspaceId];
    }
}
