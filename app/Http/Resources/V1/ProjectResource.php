<?php

namespace App\Http\Resources\V1;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Project
 */
class ProjectResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'name' => $this->name,
            'type' => $this->type,
            'status' => $this->status,
            'description' => $this->description,
            'start_on' => $this->start_on?->toDateString(),
            'due_on' => $this->due_on?->toDateString(),
        ];
    }
}
