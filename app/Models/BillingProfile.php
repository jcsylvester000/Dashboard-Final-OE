<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * How a client is billed. Money in minor units (centavos); tax in basis points.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $currency
 * @property string $cycle
 * @property int $retainer_minor
 * @property int $included_minutes
 * @property string $overage_rule
 * @property int $terms_days
 * @property int $tax_rate_bp
 * @property string $invoice_series
 * @property array<string, string|null>|null $bill_to
 * @property string|null $payment_instructions
 * @property int|null $rate_template_id
 * @property Carbon|null $rate_template_applied_at
 */
#[Fillable(['workspace_id', 'currency', 'cycle', 'retainer_minor', 'included_minutes', 'overage_rule', 'terms_days', 'tax_rate_bp', 'invoice_series', 'bill_to', 'payment_instructions'])]
class BillingProfile extends Model
{
    /** Always available; config('billing.currencies') can add more (E-101). */
    public const CURRENCIES = ['PHP', 'USD', 'EUR'];

    /**
     * Currencies a client can be billed in (config list, known currencies only).
     *
     * @return list<string>
     */
    public static function currencies(): array
    {
        $meta = (array) config('billing.currency_meta', []);
        $list = array_values(array_filter((array) config('billing.currencies', self::CURRENCIES), fn ($c) => is_string($c) && isset($meta[$c])));

        return $list === [] ? self::CURRENCIES : $list;
    }

    public const CYCLES = ['monthly' => 'Monthly', 'quarterly' => 'Quarterly'];

    public const OVERAGE_RULES = ['bill' => 'Bill hours over the retainer', 'absorb' => 'Absorb (no overage billed)'];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'currency' => 'PHP',
        'cycle' => 'monthly',
        'retainer_minor' => 0,
        'included_minutes' => 0,
        'overage_rule' => 'bill',
        'terms_days' => 15,
        'tax_rate_bp' => 0,
        'invoice_series' => 'INV',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retainer_minor' => 'integer',
            'included_minutes' => 'integer',
            'terms_days' => 'integer',
            'tax_rate_bp' => 'integer',
            'bill_to' => 'array',
            'rate_template_applied_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
