<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;

/**
 * A row in the member's inbox (Laravel database notification + inbox columns).
 *
 * @property string $id
 * @property string $type
 * @property string $kind
 * @property int|null $workspace_id
 * @property bool $quiet
 * @property array<string, mixed> $data
 * @property Carbon|null $read_at
 * @property Carbon|null $snoozed_until
 * @property Carbon|null $done_at
 * @property Carbon $created_at
 */
class InboxNotification extends DatabaseNotification
{
    /**
     * @var array<string, string>
     */
    protected $casts = [
        'data' => 'array',
        'read_at' => 'datetime',
        'snoozed_until' => 'datetime',
        'done_at' => 'datetime',
        'quiet' => 'boolean',
    ];

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * The user's own notifications, limited to workspaces they can still open.
     *
     * @param  Builder<InboxNotification>  $query
     */
    public function scopeForUser(Builder $query, User $user): void
    {
        $query->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey())
            ->where(fn (Builder $q) => $q->whereNull('workspace_id')
                ->orWhereIn('workspace_id', Workspace::query()->visibleTo($user)->select('id')));
    }

    /**
     * Not done and not snoozed into the future.
     *
     * @param  Builder<InboxNotification>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('done_at')
            ->where(fn (Builder $q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
    }

    /**
     * What the bell counts: unread, active, pushed (not digest-only).
     *
     * @param  Builder<InboxNotification>  $query
     */
    public function scopeBell(Builder $query): void
    {
        $query->active()->whereNull('read_at')->where('quiet', false);
    }
}
