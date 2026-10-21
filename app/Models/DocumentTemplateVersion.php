<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * E-52: a saved earlier version of a document template.
 *
 * @property int $id
 * @property int $document_template_id
 * @property int $version
 * @property string $body
 * @property int|null $saved_by
 * @property Carbon $created_at
 */
#[Fillable(['document_template_id', 'version', 'body', 'saved_by', 'created_at'])]
class DocumentTemplateVersion extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'version' => 'integer'];
    }
}
