<?php

namespace App\Policies;

use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\Project;
use App\Models\User;

/**
 * Project rights follow the user's role in the project's workspace.
 */
class ProjectPolicy
{
    public function __construct(private readonly WorkspaceAccess $access) {}

    public function view(User $user, Project $project): bool
    {
        return $this->access->canView($user, $project->workspace);
    }

    public function update(User $user, Project $project): bool
    {
        return $this->access->canContribute($user, $project->workspace);
    }

    public function delete(User $user, Project $project): bool
    {
        return $this->access->canLead($user, $project->workspace);
    }
}
