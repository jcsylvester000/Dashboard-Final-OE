<?php

namespace App\Models;

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
 */
#[Fillable(['workspace_id', 'project_id', 'description', 'amount_minor', 'incurred_on', 'is_billable', 'created_by'])]
class Expense extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'incurred_on' => 'date', 'is_billable' => 'boolean'];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
