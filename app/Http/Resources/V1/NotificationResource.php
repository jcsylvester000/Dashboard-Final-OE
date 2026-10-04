<?php

namespace App\Http\Resources\V1;

use App\Models\InboxNotification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InboxNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'title' => $this->data['title'] ?? null,
            'body' => $this->data['body'] ?? null,
            'url' => $this->data['url'] ?? null,
            'workspace_id' => $this->workspace_id,
            'read' => $this->read_at !== null,
            'done' => $this->done_at !== null,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
