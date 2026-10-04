<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Permissions;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\User;
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
}
