<?php

namespace App\Domain\Work;

use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Workspace;

/**
 * E-26: is the task's preview link up? Checked when it is set and every 15 minutes
 * (previews:check) while the task is open. Only public addresses are fetched (same
 * guard as link previews). The assignee hears once when it breaks, and again only
 * after it has come back up and broken again.
 */
class PreviewChecks
{
    public function __construct(
        private readonly LinkPreview $http,
        private readonly Notifier $notifier,
    ) {}

    public function check(Task $task): ?string
    {
        if ($task->preview_url === null || $task->preview_url === '') {
            return null;
        }
        $code = $this->http->status($task->preview_url);
        $up = $code !== null && $code >= 200 && $code < 400;
        $wasDown = $task->preview_status === 'down';
        $task->forceFill([
            'preview_status' => $up ? 'up' : 'down',
            'preview_http' => $code,
            'preview_checked_at' => now(),
            'preview_alerted_at' => $up ? null : $task->preview_alerted_at,
        ])->saveQuietly();

        if (! $up && ! $wasDown && $task->preview_alerted_at === null && $task->assignee_id !== null) {
            $workspace = Workspace::query()->find($task->workspace_id);
            if ($workspace !== null) {
                $this->notifier->send([$task->assignee_id], NotificationKind::Preview, $workspace, [
                    'title' => __('Preview link is down'),
                    'body' => __(':task: :why', ['task' => $task->title, 'why' => $code !== null ? 'HTTP '.$code : __('not reachable')]),
                    'url' => $this->notifier->taskUrl($task, $workspace),
                    'task_id' => $task->id,
                ]);
                $task->forceFill(['preview_alerted_at' => now()])->saveQuietly();
            }
        }

        return $task->preview_status;
    }

    /** Open tasks with a preview link. */
    public function checkAll(): int
    {
        $done = TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id')->all();
        $count = 0;
        Task::query()->whereNotNull('preview_url')->whereNotIn('status_id', $done)->orderBy('id')
            ->each(function (Task $task) use (&$count) {
                $this->check($task);
                $count++;
            });

        return $count;
    }
}
