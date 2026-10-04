<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $workflow_template_id
 * @property int $position
 * @property int|null $department_id
 * @property string $title
 * @property string|null $description
 * @property int $offset_days
 * @property bool $depends_on_previous
 */
#[Fillable(['workflow_template_id', 'position', 'department_id', 'title', 'description', 'offset_days', 'depends_on_previous'])]
class WorkflowTemplateStep extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'offset_days' => 'integer',
            'depends_on_previous' => 'boolean',
        ];
    }

    /** @return BelongsTo<WorkflowTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id');
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
