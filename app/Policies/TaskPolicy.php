<?php

namespace App\Policies;

use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\Department;
use App\Models\Project;
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
     * Sign off work that is In Review: workspace leads, the project lead, or the lead of
     * the task's department. The assignee can't approve their own work unless they lead the workspace.
     */
    public function review(User $user, Task $task): bool
    {
        if (! $this->access->canContribute($user, $task->workspace)) {
            return false;
        }

        if ($this->access->canLead($user, $task->workspace)) {
            return true;
        }

        if ($task->assignee_id === $user->id) {
            return false;
        }

        // E-07: anyone named as a reviewer on the task.
        if ($task->reviewers()->where('user_id', $user->id)->exists()) {
            return true;
        }

        // Read the lead columns directly: pages eager-load project/department with only a few
        // columns, so the loaded relations may not include lead_user_id.
        return ($task->project_id !== null && (int) Project::query()->whereKey($task->project_id)->value('lead_user_id') === $user->id)
            || ($task->department_id !== null && (int) Department::query()->whereKey($task->department_id)->value('lead_user_id') === $user->id);
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
