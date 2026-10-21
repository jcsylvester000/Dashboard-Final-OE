<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * E-37: When (trigger) -> If (conditions) -> Then (actions), per client.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $trigger status_changed | label_added | file_uploaded | due_soon
 * @property string|null $trigger_value
 * @property array<string, mixed> $conditions
 * @property list<array<string, mixed>> $actions
 * @property bool $enabled
 * @property int $runs
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'name', 'trigger', 'trigger_value', 'conditions', 'actions', 'enabled', 'created_by'])]
class AutomationRule extends Model
{
    public const TRIGGERS = [
        'status_changed' => 'Status changes to',
        'label_added' => 'Label added',
        'file_uploaded' => 'File uploaded',
        'due_soon' => 'Due within 24 hours',
    ];

    public const ACTIONS = [
        'assign' => 'Assign to',
        'set_status' => 'Set status',
        'add_label' => 'Add label',
        'notify' => 'Notify',
        'create_subtask' => 'Create subtask',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['conditions' => 'array', 'actions' => 'array', 'enabled' => 'boolean', 'runs' => 'integer'];
    }

    /** @return HasMany<AutomationRun, $this> */
    public function runLog(): HasMany
    {
        return $this->hasMany(AutomationRun::class)->latest('id');
    }
}
