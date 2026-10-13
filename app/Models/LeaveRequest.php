<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Time off requested by a team member, approved by their department lead (or an admin).
 *
 * @property int $id
 * @property int $user_id
 * @property string $type
 * @property CarbonInterface $starts_on
 * @property CarbonInterface $ends_on
 * @property int $working_days
 * @property string|null $reason
 * @property string $status pending | approved | rejected | cancelled
 * @property int|null $reviewed_by
 * @property CarbonInterface|null $reviewed_at
 * @property string|null $review_note
 * @property CarbonInterface|null $created_at
 */
#[Fillable(['user_id', 'type', 'starts_on', 'ends_on', 'working_days', 'reason'])]
class LeaveRequest extends Model
{
    public const TYPES = [
        'vacation' => 'Vacation',
        'sick' => 'Sick',
        'emergency' => 'Emergency',
        'other' => 'Other',
    ];

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'reviewed_at' => 'datetime',
            'working_days' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
