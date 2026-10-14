<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-68: client credit ledger, append-only. A positive row adds credit (an overpayment),
 * a negative row uses it (applied to an invoice). The balance is the sum per currency.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $currency
 * @property int $amount_minor
 * @property int|null $payment_id
 * @property int|null $invoice_id
 * @property string|null $note
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
#[Fillable(['workspace_id', 'currency', 'amount_minor', 'payment_id', 'invoice_id', 'note', 'created_by'])]
class ClientCredit extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'created_at' => 'datetime'];
    }

    public static function balance(int $workspaceId, string $currency): int
    {
        return (int) static::query()->where('workspace_id', $workspaceId)->where('currency', $currency)->sum('amount_minor');
    }

    /**
     * Credit per currency for one client, only currencies with a balance.
     *
     * @return array<string, int>
     */
    public static function balances(int $workspaceId): array
    {
        $out = [];
        $rows = static::query()->where('workspace_id', $workspaceId)
            ->groupBy('currency')->selectRaw('currency, SUM(amount_minor) as total')->toBase()->get();
        foreach ($rows as $row) {
            $total = (int) ($row->total ?? 0);
            if ($total !== 0) {
                $out[(string) $row->currency] = $total;
            }
        }

        return $out;
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
