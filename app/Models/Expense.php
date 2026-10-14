<?php

namespace App\Models;

use App\Domain\Billing\FinanceSettings;
use App\Domain\Billing\Money;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Billable expense (ad spend pass-through, stock photos, plugins...). Billed once.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $project_id
 * @property string $description
 * @property int $amount_minor
 * @property Carbon $incurred_on
 * @property bool $is_billable
 * @property int|null $invoice_item_id
 * @property int|null $created_by
 * @property string|null $category
 * @property int $markup_bp handling markup on the billed amount (E-75)
 * @property bool $taxable
 * @property string|null $receipt_path private disk
 * @property string|null $receipt_name
 * @property Carbon|null $approved_at only approved expenses go on drafts
 * @property int|null $approved_by
 */
#[Fillable(['workspace_id', 'project_id', 'description', 'amount_minor', 'incurred_on', 'is_billable', 'created_by', 'category', 'markup_bp', 'taxable'])]
class Expense extends Model
{
    public const CATEGORIES = [
        'ad_spend' => 'Ad spend',
        'software' => 'Software / subscriptions',
        'stock' => 'Stock photos, fonts, music',
        'printing' => 'Printing',
        'travel' => 'Travel',
        'shipping' => 'Shipping / courier',
        'other' => 'Other',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['markup_bp' => 0, 'taxable' => true];

    protected static function booted(): void
    {
        // E-75: with the expense check off (the default) Finance's own entry is the approval.
        static::creating(function (Expense $expense) {
            if ($expense->approved_at === null && ! app(FinanceSettings::class)->get()['expense_approval']) {
                $expense->forceFill(['approved_at' => now(), 'approved_by' => $expense->created_by]);
            }
        });
    }

    public function isApproved(): bool
    {
        return $this->approved_at !== null;
    }

    /** What the client is billed: cost plus markup. */
    public function billedMinor(): int
    {
        return $this->amount_minor + Money::mulDiv($this->amount_minor, $this->markup_bp, 10000);
    }

    public function isBilled(): bool
    {
        return $this->invoice_item_id !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'incurred_on' => 'date',
            'is_billable' => 'boolean',
            'markup_bp' => 'integer',
            'taxable' => 'boolean',
            'approved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
