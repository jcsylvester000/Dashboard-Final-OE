<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * E-41 (D-05): an optional Slack or Microsoft Teams (Workflows) channel for one
 * client. Off until a test message works; messages carry only the event, the task
 * title and a link. The webhook URL is a secret: encrypted at rest, shown masked.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $provider slack | teams
 * @property string $name
 * @property string $webhook_url
 * @property list<string> $events
 * @property bool $enabled
 * @property Carbon|null $last_ok_at
 * @property string|null $last_error
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'provider', 'name', 'webhook_url', 'events', 'enabled', 'created_by'])]
class ChatChannel extends Model
{
    public const EVENTS = [
        'review_requested' => 'Ready for review',
        'approved' => 'Approved',
        'changes_requested' => 'Changes requested',
        'done' => 'Task done',
    ];

    /** @var list<string> */
    protected $hidden = ['webhook_url'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['webhook_url' => 'encrypted', 'events' => 'array', 'enabled' => 'boolean', 'last_ok_at' => 'datetime'];
    }

    /** "hooks.slack.com/…a1b2": enough to recognise, never enough to use. */
    public function maskedUrl(): string
    {
        $host = (string) parse_url($this->webhook_url, PHP_URL_HOST);

        return $host.'/…'.substr($this->webhook_url, -4);
    }
}
