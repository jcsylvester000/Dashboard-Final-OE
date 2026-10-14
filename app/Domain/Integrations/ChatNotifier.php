<?php

namespace App\Domain\Integrations;

use App\Jobs\SendChatMessage;
use App\Models\ChatChannel;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * E-41 (D-05): optional Slack / Microsoft Teams (Workflows) messages for a client.
 * Only the event, the task title and a link are ever sent: no hours, rates, amounts
 * or names. Webhook hosts are allow-listed; legacy Office 365 connector URLs
 * (webhook.office.com, retired in 2026) are refused.
 */
class ChatNotifier
{
    /** host suffixes per provider */
    public const HOSTS = [
        'slack' => ['hooks.slack.com'],
        'teams' => ['logic.azure.com', 'powerautomate.com', 'powerplatform.com'],
    ];

    /** Throws when the URL is not a webhook of that provider. */
    public static function assertWebhook(string $provider, string $url): void
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || $host === '') {
            throw ValidationException::withMessages(['webhook_url' => __('Use the https webhook link from Slack or Teams.')]);
        }
        if ($host === 'webhook.office.com' || str_ends_with($host, '.webhook.office.com')) {
            throw ValidationException::withMessages(['webhook_url' => __('Office 365 connector webhooks were retired by Microsoft. In Teams, create a Workflows webhook ("Send webhook alerts to a channel") and paste that link.')]);
        }
        foreach (self::HOSTS[$provider] ?? [] as $suffix) {
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                return;
            }
        }
        throw ValidationException::withMessages(['webhook_url' => $provider === 'slack'
            ? __('Slack webhook links start with https://hooks.slack.com/.')
            : __('Teams Workflows webhook links are on logic.azure.com, powerautomate.com or powerplatform.com.')]);
    }

    /** Queue a message to every enabled channel of the client that wants this event. */
    public function notify(Workspace $workspace, string $event, string $title, string $url): int
    {
        if (! array_key_exists($event, ChatChannel::EVENTS)) {
            return 0;
        }
        $count = 0;
        ChatChannel::query()->where('workspace_id', $workspace->id)->where('enabled', true)->get()
            ->filter(fn (ChatChannel $c) => in_array($event, $c->events, true))
            ->each(function (ChatChannel $c) use ($event, $title, $url, &$count) {
                SendChatMessage::dispatch($c->id, ChatChannel::EVENTS[$event].': '.$title, $url);
                $count++;
            });

        return $count;
    }

    /**
     * Send now; records the outcome on the channel. Returns null on success, else the error.
     */
    public function send(ChatChannel $channel, string $text, string $url): ?string
    {
        $text = mb_substr($text, 0, 300);
        $payload = $channel->provider === 'slack'
            ? ['text' => '<'.$url.'|'.str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text).'>']
            : ['type' => 'message', 'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [['type' => 'TextBlock', 'text' => $text, 'wrap' => true]],
                    'actions' => [['type' => 'Action.OpenUrl', 'title' => 'Open', 'url' => $url]],
                ],
            ]]];
        try {
            self::assertWebhook($channel->provider, $channel->webhook_url); // re-checked on every send
            $response = Http::timeout(5)->connectTimeout(5)->withOptions(['allow_redirects' => false])->asJson()->post($channel->webhook_url, $payload);
            $error = $response->successful() ? null : 'HTTP '.$response->status();
        } catch (Throwable $e) {
            $error = $e instanceof ValidationException ? 'Webhook link is not allowed.' : 'Could not reach the chat service.';
        }
        $channel->forceFill($error === null ? ['last_ok_at' => now(), 'last_error' => null] : ['last_error' => $error])->save();

        return $error;
    }
}
