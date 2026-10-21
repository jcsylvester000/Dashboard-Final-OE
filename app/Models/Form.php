<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * E-53: a form built by a lead (intake, QA, feedback). The draft is edited freely;
 * publishing freezes it as a new version, so old submissions keep their own fields.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $draft
 * @property array<string, mixed> $output E-54
 * @property int|null $current_version_id
 * @property string|null $public_token E-42
 * @property bool $archived
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'name', 'description', 'draft', 'output', 'current_version_id', 'public_token', 'archived', 'created_by'])]
class Form extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['draft' => 'array', 'output' => 'array', 'archived' => 'boolean'];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'current_version_id');
    }

    /** @return HasMany<FormVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class)->orderByDesc('version');
    }

    /** @return HasMany<FormSubmission, $this> */
    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class);
    }
}
