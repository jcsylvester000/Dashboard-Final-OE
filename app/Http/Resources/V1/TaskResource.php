<?php

namespace App\Http\Resources\V1;

use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Load: status, assignee, department, project (see TaskController::WITH).
 *
 * @mixin Task
 */
class TaskResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'project_id' => $this->project_id,
            'parent_id' => $this->parent_id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority,
            'status' => $this->whenLoaded('status', fn () => ['id' => $this->status->id, 'name' => $this->status->name, 'category' => $this->status->category]),
            'department' => $this->whenLoaded('department', fn () => $this->department?->only(['id', 'name', 'slug'])),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee?->only(['id', 'name'])),
            'work_details' => $this->work_details ?? (object) [],
            'due_on' => $this->due_on?->toDateString(),
            'estimate_minutes' => $this->estimate_minutes,
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
