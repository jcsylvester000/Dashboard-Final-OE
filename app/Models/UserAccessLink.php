<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One-time account setup / password reset link, shared manually by an admin.
 *
 * @property int $id
 * @property int $user_id
 * @property string $purpose
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $revoked_at
 * @property int|null $created_by
 * @property Carbon $created_at
 */
#[Fillable(['user_id', 'purpose', 'token_hash', 'expires_at', 'created_by'])]
class UserAccessLink extends Model
{
    public const PURPOSE_SETUP = 'setup';

    public const PURPOSE_RESET = 'reset';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function status(): string
    {
        return match (true) {
            $this->used_at !== null => 'used',
            $this->revoked_at !== null => 'revoked',
            $this->expires_at->isPast() => 'expired',
            default => 'active',
        };
    }

    /**
     * @param  Builder<UserAccessLink>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('used_at')->whereNull('revoked_at');
    }
}
