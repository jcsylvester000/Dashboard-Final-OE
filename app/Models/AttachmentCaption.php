<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-19: a caption track (WebVTT) for one video version.
 *
 * @property int $id
 * @property int $attachment_id
 * @property string $label
 * @property string|null $language
 * @property string $key
 * @property int $cues
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['attachment_id', 'label', 'language', 'key', 'cues', 'created_by'])]
class AttachmentCaption extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['cues' => 'integer'];
    }

    /** @return BelongsTo<Attachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }
}
