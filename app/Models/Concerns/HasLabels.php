<?php

namespace App\Models\Concerns;

use App\Models\Label;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Workspace colour labels on any model (projects now, tasks in P3).
 */
trait HasLabels
{
    /**
     * @return MorphToMany<Label, $this>
     */
    public function labels(): MorphToMany
    {
        return $this->morphToMany(Label::class, 'labelable');
    }
}
