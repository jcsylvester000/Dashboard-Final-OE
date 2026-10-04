<?php

namespace App\Notifications;

use App\Domain\Notifications\NotificationKind;
use App\Notifications\Channels\InboxChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * One in-app alert. Stored in the inbox; realtime ones are also pushed over
 * Reverb to the member's private channel. Never sent by email.
 */
class WorkNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array{title: string, body?: string|null, url: string, workspace?: string|null, actor?: string|null, task_id?: int|null}  $payload
     */
    public function __construct(
        public readonly NotificationKind $kind,
        public readonly array $payload,
        public readonly ?int $workspaceId,
        public readonly bool $quiet = false,
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        $channels = [InboxChannel::class];

        if (! $this->quiet && ! in_array(config('broadcasting.default'), [null, 'null'], true)) {
            $channels[] = 'broadcast';
        }

        return $channels;
    }

    public function databaseType(object $notifiable): string
    {
        return $this->kind->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->payload;
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'kind' => $this->kind->value,
            'title' => $this->payload['title'],
            'body' => $this->payload['body'] ?? null,
            'url' => $this->payload['url'],
        ]);
    }
}
