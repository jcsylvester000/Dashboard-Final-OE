<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Permissions;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\ActivityLog;
use App\Models\Comment;
use App\Models\Department;
use App\Models\InboxNotification;
use App\Models\Mention;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Personal dashboard. P1 shows the member's profile and team snapshot;
 * P5 replaces the placeholders with tasks, due dates, mentions and workspaces.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user()->loadMissing('primaryDepartment:id,name,color', 'departments:id,name,color');

        $canSeeTeam = $user->can(Permissions::USERS_VIEW);

        return Inertia::render('Dashboard', [
            'profile' => [
                'name' => $user->name,
                'title' => $user->title,
                'primaryDepartment' => $user->primaryDepartment?->only(['id', 'name', 'color']),
                'departments' => $user->departments->map->only(['id', 'name', 'color'])->values(),
                'roles' => $user->getRoleNames()->values(),
                'lastLoginAt' => $user->last_login_at?->toIso8601String(),
                'twoFactorEnabled' => $user->two_factor_confirmed_at !== null,
            ],
            'team' => $canSeeTeam ? [
                'activeMembers' => User::active()->count(),
                'inactiveMembers' => User::where('is_active', false)->count(),
                'departments' => Department::withCount(['members' => fn ($q) => $q->where('is_active', true)])
                    ->orderBy('position')
                    ->get(['id', 'name', 'color'])
                    ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name, 'color' => $d->color, 'members' => $d->members_count]),
            ] : null,
            'workspaces' => Workspace::query()
                ->visibleTo($user)
                ->where('status', '!=', 'archived')
                ->whereHas('members', fn ($q) => $q->whereKey($user->id))
                ->withCount(['projects as open_projects_count' => fn ($q) => $q->whereNotIn('status', ['completed', 'archived'])])
                ->withCount(['tasks as my_open_count' => fn ($q) => $q->where('assignee_id', $user->id)->whereNotIn('status_id', $this->doneIds())])
                ->orderBy('name')
                ->limit(12)
                ->get(['id', 'name', 'slug', 'color'])
                ->map(fn (Workspace $w): array => [
                    'id' => $w->id, 'name' => $w->name, 'slug' => $w->slug, 'color' => $w->color,
                    'openProjects' => $w->open_projects_count,
                    'myOpenTasks' => (int) $w->getAttribute('my_open_count'),
                ]),
            'mentions' => $this->recentMentions($user),
            'digest' => $this->digest($user),
            'myTasks' => $this->myTaskCounts($user),
            'nextTasks' => $this->nextTasks($user),
            'recentActivity' => $user->can(Permissions::ACTIVITY_VIEW)
                ? ActivityLog::with('actor:id,name')
                    ->latest('id')
                    ->limit(8)
                    ->get()
                    ->map(fn (ActivityLog $log) => [
                        'id' => $log->id,
                        'action' => $log->action,
                        'actor' => $log->actor?->name,
                        'at' => $log->created_at->toIso8601String(),
                    ])
                : null,
        ]);
    }

    /**
     * In-app daily digest: what needs attention plus the quiet (digest-mode) alerts of the last day.
     *
     * @return array<string, mixed>
     */
    private function digest(User $user): array
    {
        $mine = fn () => InboxNotification::query()->forUser($user);
        $openTasks = fn () => Task::query()->where('assignee_id', $user->id)
            ->whereNotIn('status_id', TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id'))
            ->whereHas('workspace', fn ($q) => $q->visibleTo($user));

        return [
            'unread' => $mine()->active()->whereNull('read_at')->count(),
            'dueToday' => $openTasks()->whereDate('due_on', now()->toDateString())->count(),
            'overdue' => $openTasks()->whereDate('due_on', '<', now()->toDateString())->count(),
            'items' => $mine()->active()
                ->where('created_at', '>=', now()->subDay())
                ->latest()
                ->limit(8)
                ->get()
                ->map(fn (InboxNotification $n): array => [
                    'id' => $n->id,
                    'kind' => $n->kind,
                    'title' => (string) ($n->data['title'] ?? ''),
                    'body' => $n->data['body'] ?? null,
                    'workspace' => $n->data['workspace'] ?? null,
                    'read' => $n->read_at !== null,
                    'quiet' => $n->quiet,
                    'created_at' => $n->created_at->toIso8601String(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Recent @mentions of the user (projects, tasks, comments) they can still open.
     *
     * @return list<array{id: int, kind: string, by: string|null, at: string, label: string, url: string, workspace: string}>
     */
    private function recentMentions(User $user): array
    {
        $access = app(WorkspaceAccess::class);

        $mentions = Mention::query()
            ->where('mentioned_user_id', $user->id)
            ->with(['author:id,name', 'mentionable' => fn ($m) => $m->morphWith([Comment::class => ['commentable']])])
            ->latest('id')
            ->limit(8)
            ->get();

        $workspaces = Workspace::query()->whereIn('id', $mentions->pluck('workspace_id')->unique()->all())->get()->keyBy('id');

        $rows = [];
        foreach ($mentions as $mention) {
            $workspace = $workspaces->get($mention->workspace_id);
            if ($workspace === null || ! $access->canView($user, $workspace)) {
                continue;
            }

            $record = $mention->mentionable;
            if ($record instanceof Project) {
                [$kind, $label, $url] = ['project', $record->name, route('workspaces.projects.show', [$workspace, $record], false)];
            } elseif ($record instanceof Task) {
                [$kind, $label, $url] = ['task', $record->title, route('workspaces.tasks.show', [$workspace, $record], false)];
            } elseif ($record instanceof Comment && $record->commentable instanceof Task) {
                $task = $record->commentable;
                [$kind, $label, $url] = ['comment', $task->title, route('workspaces.tasks.show', [$workspace, $task], false)];
            } else {
                continue;
            }

            $rows[] = [
                'id' => $mention->id,
                'kind' => $kind,
                'by' => $mention->author?->name,
                'at' => $mention->created_at->toIso8601String(),
                'label' => $label,
                'url' => $url,
                'workspace' => $workspace->name,
            ];
        }

        return array_slice($rows, 0, 6);
    }

    /**
     * @return array{open: int, overdue: int, dueWeek: int}
     */
    private function myTaskCounts(User $user): array
    {
        $today = now()->toDateString();
        $rows = Task::query()->toBase()
            ->where('assignee_id', $user->id)
            ->whereNull('deleted_at')
            ->whereNotIn('status_id', $this->doneIds())
            ->whereIn('workspace_id', Workspace::query()->visibleTo($user)->select('id'))
            ->pluck('due_on');

        $weekEnd = now()->endOfWeek(CarbonImmutable::SUNDAY)->toDateString();
        $overdue = 0;
        $dueWeek = 0;
        foreach ($rows as $due) {
            $d = $due !== null ? substr((string) $due, 0, 10) : null;
            if ($d !== null && $d < $today) {
                $overdue++;
            } elseif ($d !== null && $d <= $weekEnd) {
                $dueWeek++;
            }
        }

        return ['open' => $rows->count(), 'overdue' => $overdue, 'dueWeek' => $dueWeek];
    }

    /**
     * My next open tasks by due date.
     *
     * @return list<array<string, mixed>>
     */
    private function nextTasks(User $user): array
    {
        return Task::query()
            ->with(['workspace:id,name,slug', 'status:id,name,color'])
            ->where('assignee_id', $user->id)
            ->whereNotIn('status_id', $this->doneIds())
            ->whereHas('workspace', fn ($q) => $q->visibleTo($user)->where('status', '!=', 'archived'))
            ->orderByRaw('due_on is null, due_on asc')
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->limit(8)
            ->get()
            ->map(fn (Task $t): array => [
                'id' => $t->id,
                'title' => $t->title,
                'priority' => $t->priority,
                'due_on' => $t->due_on?->toDateString(),
                'status' => $t->status->name,
                'workspace' => $t->workspace->name,
                'workspaceSlug' => $t->workspace->slug,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function doneIds(): array
    {
        return TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }
}
