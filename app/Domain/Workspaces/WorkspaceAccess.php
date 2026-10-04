<?php

namespace App\Domain\Workspaces;

use App\Domain\Identity\Permissions;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Answers "what can this user do in this workspace?".
 *
 * Effective role:
 *  - Super Admin or `workspaces.manage`  => owner everywhere
 *  - workspace member                     => their membership role
 *  - `workspaces.view-all` (non-member)   => guest (read-only)
 *  - otherwise                            => null (no access)
 *
 * Capabilities by role:
 *  owner  : everything, incl. settings, members, archive
 *  lead   : manage projects + labels, delete projects
 *  member : create / edit projects, add links and labels
 *  guest  : read-only
 */
class WorkspaceAccess
{
    public function role(User $user, Workspace $workspace): ?string
    {
        if ($user->isSuperAdmin() || $user->can(Permissions::WORKSPACES_MANAGE)) {
            return Workspace::ROLE_OWNER;
        }

        $role = DB::table('workspace_user')
            ->where('workspace_id', $workspace->getKey())
            ->where('user_id', $user->getKey())
            ->value('role');

        if ($role === null && $user->can(Permissions::WORKSPACES_VIEW_ALL)) {
            $role = Workspace::ROLE_GUEST;
        }

        return is_string($role) ? $role : null;
    }

    public function canView(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace) !== null;
    }

    /** Create and edit projects, attach labels, add links. */
    public function canContribute(User $user, Workspace $workspace): bool
    {
        return ! $workspace->isArchived()
            && in_array($this->role($user, $workspace), [Workspace::ROLE_OWNER, Workspace::ROLE_LEAD, Workspace::ROLE_MEMBER], true);
    }

    /** Manage labels, delete projects. */
    public function canLead(User $user, Workspace $workspace): bool
    {
        return ! $workspace->isArchived()
            && in_array($this->role($user, $workspace), [Workspace::ROLE_OWNER, Workspace::ROLE_LEAD], true);
    }

    /** Settings, members, archive / restore. Works on archived workspaces so they can be restored. */
    public function canOwn(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace) === Workspace::ROLE_OWNER;
    }
}
