<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-99: a personal data incident or breach, for the admins' log. Describe the kinds of
 * data involved, never copy the personal data itself in here.
 *
 * @property int $id
 * @property string $title
 * @property Carbon $discovered_at
 * @property Carbon|null $occurred_at
 * @property string $description
 * @property string|null $data_involved
 * @property int|null $people_affected
 * @property string $severity
 * @property string $status
 * @property Carbon|null $npc_notified_at
 * @property Carbon|null $people_notified_at
 * @property string|null $actions
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'discovered_at', 'occurred_at', 'description', 'data_involved', 'people_affected', 'severity', 'status', 'npc_notified_at', 'people_notified_at', 'actions'])]
class PrivacyIncident extends Model
{
    public const SEVERITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High'];

    public const STATUSES = ['open' => 'Open', 'contained' => 'Contained', 'closed' => 'Closed'];

    /** Guidance shown in the log: NPC Circular 16-03 notification window (confirm with counsel). */
    public const NOTIFY_WITHIN_HOURS = 72;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'discovered_at' => 'datetime',
            'occurred_at' => 'datetime',
            'npc_notified_at' => 'datetime',
            'people_notified_at' => 'datetime',
            'people_affected' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
