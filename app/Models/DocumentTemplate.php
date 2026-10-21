<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * E-52: an agency document template (proposal, SOW, brief, monthly report) written in
 * Markdown with merge fields; every save keeps the previous version.
 *
 * @property int $id
 * @property string $name
 * @property string $kind
 * @property string $body
 * @property int $version
 * @property bool $archived
 * @property int $uses
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'kind', 'body', 'version', 'archived', 'created_by'])]
class DocumentTemplate extends Model
{
    public const KINDS = ['proposal' => 'Proposal', 'sow' => 'Statement of work', 'brief' => 'Creative brief', 'report' => 'Monthly report', 'document' => 'Other document'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer', 'archived' => 'boolean', 'uses' => 'integer'];
    }

    /** @return HasMany<DocumentTemplateVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentTemplateVersion::class)->orderByDesc('version');
    }
}
