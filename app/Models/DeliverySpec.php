<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * E-18: what a video deliverable must be (per client): aspect, minimum size, maximum
 * length and file size, captions. Matched to tasks by channel / deliverable, or chosen.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string|null $channel
 * @property string|null $deliverable
 * @property string|null $aspect "9:16"
 * @property int|null $min_width
 * @property int|null $min_height
 * @property int|null $max_duration_s
 * @property int|null $max_size_mb
 * @property bool $captions_required
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'name', 'channel', 'deliverable', 'aspect', 'min_width', 'min_height', 'max_duration_s', 'max_size_mb', 'captions_required', 'created_by'])]
class DeliverySpec extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['min_width' => 'integer', 'min_height' => 'integer', 'max_duration_s' => 'integer', 'max_size_mb' => 'integer', 'captions_required' => 'boolean'];
    }
}
