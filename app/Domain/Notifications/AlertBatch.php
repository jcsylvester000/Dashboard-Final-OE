<?php

namespace App\Domain\Notifications;

use App\Models\User;
use App\Models\Workspace;

/**
 * Groups the alerts one bulk action produces (finding R-10): instead of "Jasmine assigned
 * you a task" four times, each person gets one "Jasmine assigned you 4 tasks" per kind.
 * Request-scoped: Notifier buffers while collecting, flush() sends the grouped alerts.
 */
class AlertBatch
{
    private int $depth = 0;

    /** @var array<string, array{users: list<int>, kind: NotificationKind, workspace: Workspace, actor: User|null, items: list<array{title: string, body?: string|null, url: string, workspace?: string|null, actor?: string|null, task_id?: int|null}>}> */
    private array $buffer = [];

    public function collecting(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Run $work with alerts buffered, then send them grouped.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function run(callable $work, Notifier $notifier): mixed
    {
        $this->depth++;
        try {
            return $work();
        } finally {
            $this->depth--;
            if ($this->depth === 0) {
                $this->flush($notifier);
            }
        }
    }

    /**
     * @param  list<int>  $userIds
     * @param  array{title: string, body?: string|null, url: string, workspace?: string|null, actor?: string|null, task_id?: int|null}  $payload
     */
    public function add(array $userIds, NotificationKind $kind, Workspace $workspace, array $payload, ?User $actor): void
    {
        foreach ($userIds as $id) {
            $key = $id.'|'.$kind->value.'|'.$workspace->id;
            $this->buffer[$key] ??= ['users' => [$id], 'kind' => $kind, 'workspace' => $workspace, 'actor' => $actor, 'items' => []];
            $this->buffer[$key]['items'][] = $payload;
        }
    }

    private function flush(Notifier $notifier): void
    {
        $buffer = $this->buffer;
        $this->buffer = [];

        foreach ($buffer as $group) {
            $items = $group['items'];
            $first = $items[0];
            if (count($items) === 1) {
                $notifier->send($group['users'], $group['kind'], $group['workspace'], $first, $group['actor']);

                continue;
            }

            $n = count($items);
            $titles = array_map(fn ($i) => (string) ($i['body'] ?? ''), $items);
            $shown = array_slice(array_filter($titles), 0, 3);
            $by = $group['actor']->name ?? __('Someone');

            $notifier->send($group['users'], $group['kind'], $group['workspace'], [
                'title' => match ($group['kind']) {
                    NotificationKind::Assigned => __(':name assigned you :n tasks', ['name' => $by, 'n' => $n]),
                    NotificationKind::Status => __(':name changed the status of :n tasks you follow', ['name' => $by, 'n' => $n]),
                    default => __(':name updated :n tasks', ['name' => $by, 'n' => $n]),
                },
                'body' => implode(' · ', $shown).($n > count($shown) ? ' '.__('and :m more', ['m' => $n - count($shown)]) : ''),
                'url' => $group['kind'] === NotificationKind::Assigned
                    ? route('workspaces.tasks.index', [$group['workspace'], 'assignee' => 'me'], false)
                    : route('workspaces.tasks.index', [$group['workspace']], false),
            ], $group['actor']);
        }
    }
}
