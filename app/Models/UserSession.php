<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-88: one signed-in browser or device. Kept beside the session store (Redis in
 * production) so people and admins can see and end sessions one by one.
 *
 * @property int $id
 * @property int $user_id
 * @property string $session_id
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 * @property Carbon $last_seen_at
 * @property Carbon|null $revoked_at
 */
#[Fillable(['user_id', 'session_id', 'ip_address', 'user_agent', 'last_seen_at'])]
class UserSession extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'last_seen_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
