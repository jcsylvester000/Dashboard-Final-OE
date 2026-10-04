<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Workspaces\WorkspacePresenter;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who works on a client workspace, with which workspace role and department.
 */
class WorkspaceMemberController extends Controller
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly WorkspacePresenter $presenter,
    ) {}

    public function index(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('view', $workspace);

        $members = $workspace->members()
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.email', 'users.title', 'users.is_active'])
            ->map(fn (User $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'email' => $m->email,
                'title' => $m->title,
                'is_active' => $m->is_active,
                'role' => $m->getRelation('membership')->getAttribute('role'),
                'department_id' => $m->getRelation('membership')->getAttribute('department_id'),
            ]);

        $canManage = Gate::allows('manageMembers', $workspace);

        return Inertia::render('workspaces/Members', [
            'workspace' => $this->presenter->header($workspace, $request->user()),
            'members' => $members,
            'roles' => Workspace::ROLES,
            'departments' => $this->presenter->departmentOptions(),
            // People who can still be added (active, not yet members).
            'candidates' => $canManage
                ? User::active()
                    ->whereNotIn('id', $members->pluck('id'))
                    ->orderBy('name')
                    ->get(['id', 'name', 'title'])
                    ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'title' => $u->title])
                : [],
        ]);
    }

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageMembers', $workspace);

        $data = $request->validate([
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where('is_active', true),
                Rule::unique('workspace_user', 'user_id')->where('workspace_id', $workspace->id),
            ],
            'role' => ['required', Rule::in(Workspace::ROLES)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ], [
            'user_id.unique' => __('That person is already a member.'),
        ]);

        $workspace->members()->attach($data['user_id'], [
            'role' => $data['role'],
            'department_id' => $data['department_id'] ?? null,
        ]);

        $this->activity->log('workspace.member-added', $workspace, [
            'user_id' => (int) $data['user_id'], 'role' => $data['role'],
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member added.')]);

        return back();
    }

    public function update(Request $request, Workspace $workspace, User $user): RedirectResponse
    {
        Gate::authorize('manageMembers', $workspace);
        $current = $this->membershipRole($workspace, $user);

        $data = $request->validate([
            'role' => ['required', Rule::in(Workspace::ROLES)],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ]);

        if ($current === Workspace::ROLE_OWNER && $data['role'] !== Workspace::ROLE_OWNER) {
            $this->ensureAnotherOwner($workspace, $user);
        }

        $workspace->members()->updateExistingPivot($user->id, [
            'role' => $data['role'],
            'department_id' => $data['department_id'] ?? null,
        ]);

        $this->activity->log('workspace.member-updated', $workspace, [
            'user_id' => $user->id, 'role' => ['from' => $current, 'to' => $data['role']],
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member updated.')]);

        return back();
    }

    public function destroy(Workspace $workspace, User $user): RedirectResponse
    {
        Gate::authorize('manageMembers', $workspace);

        if ($this->membershipRole($workspace, $user) === Workspace::ROLE_OWNER) {
            $this->ensureAnotherOwner($workspace, $user);
        }

        DB::transaction(function () use ($workspace, $user) {
            $projectIds = $workspace->projects()->withTrashed()->pluck('id');

            // Leaving a workspace also removes them from its projects.
            DB::table('project_user')->whereIn('project_id', $projectIds)->where('user_id', $user->id)->delete();
            DB::table('projects')->whereIn('id', $projectIds)->where('lead_user_id', $user->id)->update(['lead_user_id' => null]);

            $workspace->members()->detach($user->id);

            if ($user->last_workspace_id === $workspace->id) {
                $user->forceFill(['last_workspace_id' => null])->saveQuietly();
            }
        });

        $this->activity->log('workspace.member-removed', $workspace, ['user_id' => $user->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name removed from the workspace.', ['name' => $user->name])]);

        return back();
    }

    private function membershipRole(Workspace $workspace, User $user): string
    {
        $role = DB::table('workspace_user')
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->value('role');

        abort_if($role === null, 404);

        return (string) $role;
    }

    /**
     * A workspace must always keep at least one owner.
     */
    private function ensureAnotherOwner(Workspace $workspace, User $leaving): void
    {
        $others = DB::table('workspace_user')
            ->where('workspace_id', $workspace->id)
            ->where('role', Workspace::ROLE_OWNER)
            ->where('user_id', '!=', $leaving->id)
            ->count();

        if ($others === 0) {
            throw ValidationException::withMessages([
                'role' => __('A workspace needs at least one owner. Make someone else owner first.'),
            ]);
        }
    }
}
