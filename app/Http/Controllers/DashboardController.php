<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Permissions;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Mention;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
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
                ->orderBy('name')
                ->limit(12)
                ->get(['id', 'name', 'slug', 'color'])
                ->map(fn (Workspace $w): array => [
                    'id' => $w->id, 'name' => $w->name, 'slug' => $w->slug, 'color' => $w->color,
                    'openProjects' => $w->open_projects_count,
                ]),
            'mentions' => $this->recentMentions($user),
            'myTasks' => [
                'open' => Task::query()->where('assignee_id', $user->id)
                    ->whereNotIn('status_id', TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id'))
                    ->whereHas('workspace', fn ($q) => $q->visibleTo($user))
                    ->count(),
                'overdue' => Task::query()->where('assignee_id', $user->id)
                    ->whereNotIn('status_id', TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id'))
                    ->whereDate('due_on', '<', now()->toDateString())
                    ->whereHas('workspace', fn ($q) => $q->visibleTo($user))
                    ->count(),
            ],
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
     * Projects where the user was @mentioned and can still open.
     *
     * @return list<array{id: int, by: string|null, at: string, project: string, projectId: int, workspace: string, workspaceSlug: string}>
     */
    private function recentMentions(User $user): array
    {
        $access = app(WorkspaceAccess::class);
        $rows = [];

        $mentions = Mention::query()
            ->where('mentioned_user_id', $user->id)
            ->where('mentionable_type', 'project')
            ->with(['author:id,name', 'mentionable' => fn ($m) => $m->morphWith([Project::class => ['workspace:id,name,slug']])])
            ->latest('id')
            ->limit(6)
            ->get();

        foreach ($mentions as $mention) {
            $project = $mention->mentionable;
            if (! $project instanceof Project) {
                continue;
            }

            $workspace = $project->workspace;
            if (! $access->canView($user, $workspace)) {
                continue;
            }

            $rows[] = [
                'id' => $mention->id,
                'by' => $mention->author?->name,
                'at' => $mention->created_at->toIso8601String(),
                'project' => $project->name,
                'projectId' => $project->id,
                'workspace' => $workspace->name,
                'workspaceSlug' => $workspace->slug,
            ];
        }

        return $rows;
    }
}
