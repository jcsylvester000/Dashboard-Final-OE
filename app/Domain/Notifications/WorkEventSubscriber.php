<?php

namespace App\Domain\Notifications;

use App\Domain\Integrations\ChatNotifier;
use App\Domain\Work\AutomationEngine;
use App\Domain\Workspaces\MentionService;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Events\Work\CommentAdded;
use App\Events\Work\HandoffReceived;
use App\Events\Work\TaskAssigned;
use App\Events\Work\TaskDueSoon;
use App\Events\Work\TaskOverdue;
use App\Events\Work\TaskStatusChanged;
use App\Events\Work\UserMentioned;
use App\Models\Comment;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns work events into in-app alerts: who hears about what.
 * Registered with Event::subscribe in AppServiceProvider.
 */
class WorkEventSubscriber
{
    public function __construct(
        private readonly Notifier $notifier,
        private readonly WorkspaceAccess $access,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(): array
    {
        return [
            TaskAssigned::class => 'onAssigned',
            UserMentioned::class => 'onMentioned',
            HandoffReceived::class => 'onHandoff',
            TaskStatusChanged::class => 'onStatusChanged',
            CommentAdded::class => 'onComment',
            TaskDueSoon::class => 'onDueSoon',
            TaskOverdue::class => 'onOverdue',
        ];
    }

    public function onAssigned(TaskAssigned $event): void
    {
        $task = $event->task;
        $workspace = $this->workspaceOf($task);
        if ($workspace === null || $task->assignee_id === null) {
            return;
        }

        $this->notifier->send([$task->assignee_id], NotificationKind::Assigned, $workspace, [
            'title' => $event->actor ? __(':name assigned you a task', ['name' => $event->actor->name]) : __('You were assigned a task'),
            'body' => $task->title,
            'url' => $this->notifier->taskUrl($task, $workspace),
            'task_id' => $task->id,
        ], $event->actor);
    }

    public function onMentioned(UserMentioned $event): void
    {
        $record = $event->record;
        $by = $event->actor->name ?? __('Someone');

        if ($record instanceof Task) {
            $workspace = $this->workspaceOf($record);
            $payload = ['body' => $record->title, 'url' => $workspace ? $this->notifier->taskUrl($record, $workspace) : '', 'task_id' => $record->id];
        } elseif ($record instanceof Comment) {
            $record->loadMissing('commentable');
            $task = $record->commentable;
            if (! $task instanceof Task) {
                return;
            }
            $workspace = $this->workspaceOf($task);
            $payload = ['body' => $task->title.': '.$this->excerpt($record->body), 'url' => $workspace ? $this->notifier->taskUrl($task, $workspace) : '', 'task_id' => $task->id];
        } elseif ($record instanceof Project) {
            // Null when the workspace was deleted (soft-deleted rows are excluded).
            $workspace = Workspace::query()->find($record->workspace_id);
            $payload = ['body' => $record->name, 'url' => $workspace ? route('workspaces.projects.show', [$workspace, $record], false) : ''];
        } else {
            return;
        }

        if ($workspace === null) {
            return;
        }

        $this->notifier->send([$event->userId], NotificationKind::Mentioned, $workspace, [
            'title' => __(':name mentioned you', ['name' => $by]),
            ...$payload,
        ], $event->actor);
    }

    public function onHandoff(HandoffReceived $event): void
    {
        $next = $event->next->loadMissing('department.lead');
        $workspace = $this->workspaceOf($next);
        if ($workspace === null) {
            return;
        }

        $recipients = $next->assignee_id !== null ? [$next->assignee_id] : $this->leadsFor($next, $workspace);

        $this->notifier->send($recipients, NotificationKind::Handoff, $workspace, [
            'title' => __(':name handed off work to :dept', ['name' => $event->actor->name, 'dept' => $next->department->name ?? __('your team')]),
            'body' => $next->title,
            'url' => $this->notifier->taskUrl($next, $workspace),
            'task_id' => $next->id,
        ], $event->actor);
    }

    public function onStatusChanged(TaskStatusChanged $event): void
    {
        $task = $event->task;
        $workspace = $this->workspaceOf($task);
        if ($workspace === null) {
            return;
        }

        if ($event->unblockedBy !== null) {
            $recipients = $task->assignee_id !== null ? [$task->assignee_id] : $this->leadsFor($task, $workspace);

            $this->notifier->send($recipients, NotificationKind::Handoff, $workspace, [
                'title' => __('Ready to start'),
                'body' => __(':task (":done" is done)', ['task' => $task->title, 'done' => $event->unblockedBy->title]),
                'url' => $this->notifier->taskUrl($task, $workspace),
                'task_id' => $task->id,
            ], $event->actor);

            return;
        }

        $statusModel = TaskStatus::ordered()->firstWhere('id', $task->status_id);
        $status = $statusModel->name ?? '';
        $followers = $this->followers($task);

        // E-37: the client's automation rules.
        if ($statusModel !== null) {
            app(AutomationEngine::class)->handle('status_changed', $task, $statusModel->slug);
        }
        // E-41: optional chat channels (title + link only).
        $this->chat($task, $workspace, TaskStatus::ordered()->firstWhere('id', $event->fromStatusId)?->slug, $statusModel?->slug);

        // Ready for review: the reviewers hear about it right away (not only in the digest).
        if ($statusModel !== null && $statusModel->slug === 'in-review') {
            $reviewers = $this->reviewersFor($task, $workspace);
            $this->notifier->send($reviewers, NotificationKind::Review, $workspace, [
                'title' => __(':name sent work for your review', ['name' => $event->actor->name ?? __('Someone')]),
                'body' => $task->title,
                'url' => $this->notifier->taskUrl($task, $workspace),
                'task_id' => $task->id,
            ], $event->actor);
            $followers = array_values(array_diff($followers, $reviewers));
        }

        $this->notifier->send($followers, NotificationKind::Status, $workspace, [
            'title' => __(':name moved a task to :status', ['name' => $event->actor->name ?? __('Someone'), 'status' => $status]),
            'body' => $task->title,
            'url' => $this->notifier->taskUrl($task, $workspace),
            'task_id' => $task->id,
        ], $event->actor);
    }

    public function onComment(CommentAdded $event): void
    {
        $task = $event->task;
        $workspace = $this->workspaceOf($task);
        if ($workspace === null) {
            return;
        }

        $recipients = array_diff($this->followers($task), $event->mentionedIds);

        $this->notifier->send($recipients, NotificationKind::Comment, $workspace, [
            'title' => __(':name commented on :task', ['name' => $event->actor->name, 'task' => Str::limit($task->title, 60)]),
            'body' => $this->excerpt($event->comment->body),
            'url' => $this->notifier->taskUrl($task, $workspace).'#comment-'.$event->comment->id,
            'task_id' => $task->id,
        ], $event->actor);
    }

    public function onDueSoon(TaskDueSoon $event): void
    {
        $task = $event->task;
        $workspace = $this->workspaceOf($task);
        if ($workspace === null || $task->assignee_id === null || $task->due_on === null) {
            return;
        }

        $this->notifier->send([$task->assignee_id], NotificationKind::DueSoon, $workspace, [
            'title' => $task->due_on->isToday() ? __('Due today') : __('Due tomorrow'),
            'body' => $task->title,
            'url' => $this->notifier->taskUrl($task, $workspace),
            'task_id' => $task->id,
        ]);
    }

    public function onOverdue(TaskOverdue $event): void
    {
        $task = $event->task->loadMissing(['assignee:id,name']);
        $workspace = $this->workspaceOf($task);
        if ($workspace === null || $task->due_on === null) {
            return;
        }

        $days = (int) $task->due_on->startOfDay()->diffInDays(now()->startOfDay());
        $url = $this->notifier->taskUrl($task, $workspace);

        if ($event->escalate) {
            $this->notifier->send($this->leadsFor($task, $workspace), NotificationKind::Escalation, $workspace, [
                'title' => trans_choice('Overdue :count day - needs a lead|Overdue :count days - needs a lead', $days),
                'body' => $task->title.' · '.($task->assignee->name ?? __('unassigned')),
                'url' => $url,
                'task_id' => $task->id,
            ]);

            return;
        }

        $recipients = $task->assignee_id !== null ? [$task->assignee_id] : $this->leadsFor($task, $workspace);

        $this->notifier->send($recipients, NotificationKind::Overdue, $workspace, [
            'title' => __('Overdue since :date', ['date' => $task->due_on->format('M j')]),
            'body' => $task->title,
            'url' => $url,
            'task_id' => $task->id,
        ]);
    }

    /**
     * The task's workspace, or null when it was archived away / deleted.
     */
    private function chat(Task $task, Workspace $workspace, ?string $from, ?string $to): void
    {
        $event = match (true) {
            $to === 'in-review' => 'review_requested',
            $from === 'in-review' && $to === 'done' => 'approved',
            $from === 'in-review' && $to === 'in-progress' => 'changes_requested',
            $to === 'done' => 'done',
            default => null,
        };
        if ($event !== null) {
            app(ChatNotifier::class)->notify($workspace, $event, ($workspace->key !== null && $task->number !== null ? $workspace->key.'-'.$task->number.' ' : '').$task->title, url($this->notifier->taskUrl($task, $workspace)));
        }
    }

    private function workspaceOf(Task $task): ?Workspace
    {
        $task->loadMissing('workspace');

        return $task->workspace;
    }

    /**
     * Watchers, assignee and reporter.
     *
     * @return list<int>
     */
    private function followers(Task $task): array
    {
        $ids = DB::table('task_watchers')->where('task_id', $task->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        return array_values(array_unique(array_filter([...$ids, $task->assignee_id, $task->reporter_id])));
    }

    /**
     * Who signs off work in review: the project lead and the department lead
     * (when they can open the workspace), else the workspace owners and leads.
     *
     * @return list<int>
     */
    private function reviewersFor(Task $task, Workspace $workspace): array
    {
        // E-07: named reviewers first.
        $named = $task->reviewers()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        if ($named !== []) {
            return array_values($named);
        }

        $ids = array_filter([
            $task->project_id !== null ? (int) DB::table('projects')->where('id', $task->project_id)->value('lead_user_id') : 0,
            $task->department_id !== null ? (int) DB::table('departments')->where('id', $task->department_id)->value('lead_user_id') : 0,
        ]);
        $ids = array_values(array_filter(array_unique($ids), fn (int $id) => $id !== $task->assignee_id
            && ($u = User::query()->find($id)) !== null && $this->access->canView($u, $workspace)));

        return $ids !== [] ? $ids : $this->leadsFor($task, $workspace);
    }

    /**
     * The task department's lead if they can open this workspace, else the workspace owners and leads.
     *
     * @return list<int>
     */
    private function leadsFor(Task $task, Workspace $workspace): array
    {
        $task->loadMissing('department.lead');
        $lead = $task->department?->lead;
        if ($lead !== null && $this->access->canView($lead, $workspace)) {
            return [$lead->id];
        }

        return DB::table('workspace_user')
            ->where('workspace_id', $workspace->id)
            ->whereIn('role', [Workspace::ROLE_OWNER, Workspace::ROLE_LEAD])
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function excerpt(string $text): string
    {
        return Str::limit((string) preg_replace([MentionService::TOKEN, MentionService::DEPARTMENT_TOKEN], '@$1', $text), 140);
    }
}
