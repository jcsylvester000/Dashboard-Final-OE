<?php

namespace App\Models\Concerns;

use App\Models\RecordLink;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Records that can cross-reference each other (workspaces, projects; tasks in P3).
 */
trait IsLinkable
{
    /**
     * Links this record created to others.
     *
     * @return MorphMany<RecordLink, $this>
     */
    public function outgoingLinks(): MorphMany
    {
        return $this->morphMany(RecordLink::class, 'source');
    }

    /**
     * Backlinks: other records that reference this one.
     *
     * @return MorphMany<RecordLink, $this>
     */
    public function incomingLinks(): MorphMany
    {
        return $this->morphMany(RecordLink::class, 'target');
    }

    abstract public function linkLabel(): string;
}
