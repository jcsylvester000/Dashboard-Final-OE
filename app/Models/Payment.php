<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Client payment, recorded manually by Finance.
 *
 * @property int $id
 * @property int $invoice_id
 * @property int $amount_minor
 * @property string $method
 * @property string|null $reference
 * @property Carbon $received_on
 * @property string|null $note
 * @property int|null $recorded_by
 * @property int $fee_minor bank/gateway charges, invoice currency (E-105)
 * @property int $withholding_minor tax withheld by the client
 * @property int|null $received_minor what reached the bank, in received_currency
 * @property string|null $received_currency
 * @property string|null $fx_rate received per 1 unit of the invoice currency
 * @property int $credit_minor overpayment moved to the client's credit (E-68)
 * @property Carbon|null $reversed_at
 * @property int|null $reversed_by
 * @property string|null $reversal_reason
 * @property string|null $php_rate PHP per 1 unit on the payment date (E-104)
 */
#[Fillable(['invoice_id', 'amount_minor', 'method', 'reference', 'received_on', 'note', 'recorded_by', 'fee_minor', 'withholding_minor', 'received_minor', 'received_currency', 'fx_rate', 'credit_minor', 'php_rate'])]
class Payment extends Model
{
    public const METHODS = [
        'bank' => 'Bank transfer',
        'gcash' => 'GCash',
        'maya' => 'Maya',
        'card' => 'Card',
        'check' => 'Check',
        'cash' => 'Cash',
        'wise' => 'Wise',
        'paypal' => 'PayPal',
        'payoneer' => 'Payoneer',
        'swift' => 'International wire (SWIFT/IBAN)',
        'credit' => 'Client credit',
    ];

    /**
     * Methods Finance picks by hand; "credit" is only used when credit is applied.
     *
     * @return array<string, string>
     */
    public static function manualMethods(): array
    {
        return array_diff_key(self::METHODS, ['credit' => true]);
    }

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['fee_minor' => 0, 'withholding_minor' => 0, 'credit_minor' => 0];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'received_on' => 'date',
            'fee_minor' => 'integer',
            'withholding_minor' => 'integer',
            'received_minor' => 'integer',
            'fx_rate' => 'decimal:8',
            'credit_minor' => 'integer',
            'reversed_at' => 'datetime',
            'php_rate' => 'decimal:6',
        ];
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    /** @return BelongsTo<Invoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
