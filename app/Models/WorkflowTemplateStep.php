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
 * @property list<int>|null $review_user_ids E-40: named reviewers for this step's task
 * @property bool $review_leads E-40: the client's leads review
 * @property string|null $review_mode all | any
 * @property int|null $review_sla_days working days before reminders
 * @property list<string>|null $checklist lines ("? " = optional)
 */
#[Fillable(['workflow_template_id', 'position', 'department_id', 'title', 'description', 'offset_days', 'depends_on_previous', 'review_user_ids', 'review_leads', 'review_mode', 'review_sla_days', 'checklist'])]
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
            'review_user_ids' => 'array',
            'review_leads' => 'boolean',
            'review_sla_days' => 'integer',
            'checklist' => 'array',
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
