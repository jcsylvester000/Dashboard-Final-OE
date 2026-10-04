<?php

namespace App\Policies;

use App\Domain\Identity\Permissions;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\User;
use App\Models\Workspace;

/**
 * Super Admin bypasses these via Gate::before.
 */
class WorkspacePolicy
{
    public function __construct(private readonly WorkspaceAccess $access) {}

    public function create(User $user): bool
    {
        return $user->can(Permissions::WORKSPACES_MANAGE);
    }

    public function view(User $user, Workspace $workspace): bool
    {
        return $this->access->canView($user, $workspace);
    }

    public function contribute(User $user, Workspace $workspace): bool
    {
        return $this->access->canContribute($user, $workspace);
    }

    public function lead(User $user, Workspace $workspace): bool
    {
        return $this->access->canLead($user, $workspace);
    }

    public function update(User $user, Workspace $workspace): bool
    {
        return $this->access->canOwn($user, $workspace);
    }

    public function manageMembers(User $user, Workspace $workspace): bool
    {
        return $this->access->canOwn($user, $workspace) && ! $workspace->isArchived();
    }
}
