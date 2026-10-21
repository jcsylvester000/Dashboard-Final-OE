<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * E-37: one run of a rule (done, skipped, failed, or stopped as a loop).
 *
 * @property int $id
 * @property int $automation_rule_id
 * @property int|null $task_id
 * @property string $outcome
 * @property string|null $detail
 * @property Carbon $created_at
 */
#[Fillable(['automation_rule_id', 'task_id', 'outcome', 'detail', 'created_at'])]
class AutomationRun extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
