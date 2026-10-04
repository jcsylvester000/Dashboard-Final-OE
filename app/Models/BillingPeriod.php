<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A client's billing period: open -> locked (time frozen) -> invoiced.
 *
 * @property int $id
 * @property int $workspace_id
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $status
 * @property Carbon|null $locked_at
 * @property int|null $locked_by
 */
#[Fillable(['workspace_id', 'starts_on', 'ends_on', 'status'])]
class BillingPeriod extends Model
{
    public const OPEN = 'open';

    public const LOCKED = 'locked';

    public const INVOICED = 'invoiced';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = ['status' => self::OPEN];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'locked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return HasMany<Invoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
