<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-99: one person acknowledged one version of the staff privacy notice.
 *
 * @property int $id
 * @property int $user_id
 * @property int $version
 * @property Carbon $acknowledged_at
 */
#[Fillable(['user_id', 'version', 'acknowledged_at'])]
class PrivacyAcknowledgement extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['version' => 'integer', 'acknowledged_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
