<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named set of per-person hourly rates (e.g. "Standard 2026", "Retainer clients").
 * Applied to a client, its rows become that client's own rate cards.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property int|null $created_by
 * @property string $currency
 */
#[Fillable(['name', 'description', 'created_by', 'currency'])]
class RateTemplate extends Model
{
    /**
     * Same default as the database column, so new in-memory models know their currency (E-100).
     *
     * @var array<string, mixed>
     */
    protected $attributes = ['currency' => 'PHP'];

    /** @return HasMany<RateTemplateItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(RateTemplateItem::class);
    }
}
