<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reusable cross-department workflow, e.g. Client Onboarding:
 * Research > Product > Development > SEO > Marketing.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $created_by
 * @property bool $working_days E-40: offsets count working days (weekends and holidays skipped)
 */
#[Fillable(['name', 'slug', 'description', 'is_active', 'created_by', 'working_days'])]
class WorkflowTemplate extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'working_days' => 'boolean'];
    }

    /** @return HasMany<WorkflowTemplateStep, $this> */
    public function steps(): HasMany
    {
        return $this->hasMany(WorkflowTemplateStep::class)->orderBy('position');
    }
}
