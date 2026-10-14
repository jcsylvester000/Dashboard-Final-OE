<?php

namespace App\Jobs;

use App\Domain\Integrations\ChatNotifier;
use App\Models\ChatChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * E-41: one chat message (title + link) to one channel, retried a few times.
 */
class SendChatMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $channelId, public readonly string $text, public readonly string $url) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120];
    }

    public function handle(ChatNotifier $chat): void
    {
        $channel = ChatChannel::query()->find($this->channelId);
        if ($channel === null || ! $channel->enabled) {
            return;
        }
        $error = $chat->send($channel, $this->text, $this->url);
        if ($error !== null && str_starts_with($error, 'HTTP 5')) {
            throw new RuntimeException($error); // retry server errors only
        }
    }
}
