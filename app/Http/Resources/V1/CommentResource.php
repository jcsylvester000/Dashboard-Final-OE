<?php

namespace App\Http\Resources\V1;

use App\Models\Comment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Body keeps @[Name](user:ID) mention tokens; clients render them as tags.
 *
 * @mixin Comment
 */
class CommentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->commentable_id,
            'body' => $this->body,
            'author' => $this->whenLoaded('author', fn () => $this->author?->only(['id', 'name'])),
            'created_at' => $this->created_at->toIso8601String(),
            'edited_at' => $this->edited_at?->toIso8601String(),
        ];
    }
}
