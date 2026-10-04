<?php

namespace App\Domain\Workspaces;

use App\Domain\Billing\WorkSummaryAccess;
use App\Domain\Identity\Permissions;
use App\Models\Department;
use App\Models\User;
use App\Models\Workspace;

/**
 * Shapes workspace data for Inertia pages (header + capability flags).
 */
class WorkspacePresenter
{
    public function __construct(private readonly WorkspaceAccess $access) {}

    /**
     * @return array<string, mixed>
     */
    public function header(Workspace $workspace, User $user): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'slug' => $workspace->slug,
            'industry' => $workspace->industry,
            'website' => $workspace->website,
            'status' => $workspace->status,
            'color' => $workspace->color,
            'description' => $workspace->description,
            'primaryContact' => $workspace->primary_contact_name,
            'myRole' => $this->access->role($user, $workspace),
            'can' => [
                'contribute' => $this->access->canContribute($user, $workspace),
                'lead' => $this->access->canLead($user, $workspace),
                'own' => $this->access->canOwn($user, $workspace),
                'manageMembers' => $this->access->canOwn($user, $workspace) && ! $workspace->isArchived(),
                'settings' => $this->access->canOwn($user, $workspace) || $this->access->canLead($user, $workspace),
                'reports' => $this->access->canLead($user, $workspace) || $user->can(Permissions::REPORTS_VIEW_ALL),
                'workSummary' => WorkSummaryAccess::allows($user, $workspace),
            ],
        ];
    }

    /**
     * Active workspace members, for pickers and @mentions.
     *
     * @return list<array{id: int, name: string, title: string|null}>
     */
    public function memberOptions(Workspace $workspace): array
    {
        return $workspace->members()
            ->where('users.is_active', true)
            ->orderBy('name')
            ->get(['users.id', 'users.name', 'users.title'])
            ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'title' => $u->title])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, color: string}>
     */
    public function departmentOptions(): array
    {
        return Department::query()->orderBy('position')->get(['id', 'name', 'color'])
            ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name, 'color' => $d->color])
            ->values()
            ->all();
    }
}
