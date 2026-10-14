<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * E-04 / E-05: a numbered pin (a thread) or a reply on one file version.
 *
 * @property int $id
 * @property int $attachment_id
 * @property int|null $parent_id
 * @property int|null $user_id
 * @property string|null $client_name E-08: written by the client on the review link (user_id null)
 * @property int|null $number
 * @property string|null $x
 * @property string|null $y
 * @property int|null $time_ms E-15: position on a video's timeline
 * @property int|null $end_ms E-15: end of a range comment
 * @property int|null $carried_from_id E-17: the open comment on the previous cut this continues
 * @property int|null $carried_from_version
 * @property int|null $page E-30: PDF page (1-based)
 * @property string|null $quote E-30: highlighted text
 * @property list<list<float>>|null $rects E-30: highlight boxes [x, y, w, h] as 0..1 of the page
 * @property list<array<string, mixed>>|null $shapes
 * @property string|null $color
 * @property string $body
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['attachment_id', 'parent_id', 'user_id', 'client_name', 'number', 'x', 'y', 'time_ms', 'end_ms', 'carried_from_id', 'carried_from_version', 'page', 'quote', 'rects', 'shapes', 'color', 'body'])]
class Annotation extends Model
{
    /** Colour-blind-safe palette (Okabe-Ito), also used for pins. */
    public const COLORS = ['#E69F00', '#56B4E9', '#009E73', '#F0E442', '#0072B2', '#D55E00', '#CC79A7', '#000000', '#FFFFFF'];

    public const SHAPE_TYPES = ['pen', 'arrow', 'rect', 'ellipse', 'text'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'x' => 'decimal:5',
            'y' => 'decimal:5',
            'shapes' => 'array',
            'resolved_at' => 'datetime',
            'number' => 'integer',
            'time_ms' => 'integer',
            'end_ms' => 'integer',
            'carried_from_version' => 'integer',
            'page' => 'integer',
            'rects' => 'array',
        ];
    }

    /** @return BelongsTo<Attachment, $this> */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(Attachment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @return HasMany<Annotation, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(Annotation::class, 'parent_id')->orderBy('id');
    }
}
