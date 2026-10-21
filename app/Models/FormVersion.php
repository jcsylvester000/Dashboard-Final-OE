<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * E-53: a published, frozen copy of a form's fields and output mapping.
 *
 * @property int $id
 * @property int $form_id
 * @property int $version
 * @property array<string, mixed> $schema
 * @property array<string, mixed> $output
 * @property int|null $published_by
 * @property Carbon $published_at
 */
#[Fillable(['form_id', 'version', 'schema', 'output', 'published_by', 'published_at'])]
class FormVersion extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['schema' => 'array', 'output' => 'array', 'version' => 'integer', 'published_at' => 'datetime'];
    }
}
