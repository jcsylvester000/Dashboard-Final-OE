<?php

namespace App\Notifications\Channels;

use App\Notifications\WorkNotification;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * Database channel that also fills the inbox columns (kind, workspace_id, quiet).
 */
class InboxChannel extends DatabaseChannel
{
    /**
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    protected function buildPayload($notifiable, Notification $notification)
    {
        $payload = parent::buildPayload($notifiable, $notification);

        if ($notification instanceof WorkNotification) {
            $payload['kind'] = $notification->kind->value;
            $payload['workspace_id'] = $notification->workspaceId;
            $payload['quiet'] = $notification->quiet;
        }

        return $payload;
    }
}
