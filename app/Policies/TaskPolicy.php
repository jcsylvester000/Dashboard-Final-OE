<?php

namespace App\Policies;

use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\Task;
use App\Models\User;

/**
 * Task rights follow the user's role in the task's workspace.
 */
class TaskPolicy
{
    public function __construct(private readonly WorkspaceAccess $access) {}

    public function view(User $user, Task $task): bool
    {
        return $this->access->canView($user, $task->workspace);
    }

    public function update(User $user, Task $task): bool
    {
        return $this->access->canContribute($user, $task->workspace);
    }

    /**
     * Leads delete anything; contributors may delete tasks they created.
     */
    public function delete(User $user, Task $task): bool
    {
        return $this->access->canLead($user, $task->workspace)
            || ($task->reporter_id === $user->id && $this->access->canContribute($user, $task->workspace));
    }
}
