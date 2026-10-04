<?php

namespace App\Domain\Notifications;

use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\WorkNotification;

/**
 * Sends one alert to a set of people, applying the rules every alert shares:
 * never to the person who caused it, only to active members who can still open
 * the workspace, and only in the mode each person chose (realtime / digest / off).
 */
class Notifier
{
    public function __construct(
        private readonly WorkspaceAccess $access,
        private readonly NotificationPreferences $preferences,
    ) {}

    /**
     * @param  iterable<int|null>  $userIds
     * @param  array{title: string, body?: string|null, url: string, workspace?: string|null, actor?: string|null, task_id?: int|null}  $payload
     * @return int number of people notified
     */
    public function send(iterable $userIds, NotificationKind $kind, Workspace $workspace, array $payload, ?User $actor = null): int
    {
        $ids = [];
        foreach ($userIds as $id) {
            // Cast first: freshly filled FK attributes can be numeric strings ("12").
            if ($id !== null && (int) $id !== $actor?->id) {
                $ids[(int) $id] = true;
            }
        }

        if ($ids === []) {
            return 0;
        }

        $payload['workspace'] ??= $workspace->name;
        $payload['actor'] ??= $actor?->name;

        $sent = 0;
        foreach (User::query()->active()->whereIn('id', array_keys($ids))->get() as $user) {
            if (! $this->access->canView($user, $workspace)) {
                continue;
            }

            $mode = $this->preferences->mode($user, $kind);
            if ($mode === NotificationKind::MODE_OFF) {
                continue;
            }

            $user->notify(new WorkNotification($kind, $payload, $workspace->id, $mode === NotificationKind::MODE_DIGEST));
            $sent++;
        }

        return $sent;
    }

    /**
     * Relative link to a task page (kept relative so it works on any host).
     */
    public function taskUrl(Task $task, Workspace $workspace): string
    {
        return route('workspaces.tasks.show', [$workspace, $task], false);
    }
}
