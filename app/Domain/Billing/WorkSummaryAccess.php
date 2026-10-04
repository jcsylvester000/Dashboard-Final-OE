<?php

namespace App\Domain\Billing;

use App\Domain\Identity\Permissions;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\User;
use App\Models\Workspace;

/**
 * Who may read a workspace's Work Summary (who worked on what): its owners and
 * leads, Finance and anyone with agency-wide reports.
 */
final class WorkSummaryAccess
{
    public static function allows(User $user, Workspace $workspace): bool
    {
        return $user->can(Permissions::REPORTS_VIEW_ALL)
            || $user->can(Permissions::BILLING_MANAGE)
            || app(WorkspaceAccess::class)->canLead($user, $workspace)
            || app(WorkspaceAccess::class)->canOwn($user, $workspace);
    }
}
