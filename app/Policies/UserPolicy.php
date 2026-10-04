<?php

namespace App\Policies;

use App\Domain\Identity\Permissions;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Record-level rules on top of the route permission checks.
 * Super Admin bypasses everything via Gate::before.
 *
 * No-escalation rule: an admin can only manage people, and grant roles,
 * whose permissions are a subset of the admin's own. Example: an Admin
 * without billing.manage cannot create a Finance user or reset a Finance
 * user's password (that would let them take over the account).
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permissions::USERS_VIEW);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permissions::USERS_MANAGE);
    }

    public function update(User $actor, User $target): bool
    {
        if (! $actor->can(Permissions::USERS_MANAGE) || $target->isSuperAdmin()) {
            return false;
        }

        if ($actor->is($target)) {
            return true;
        }

        return $this->covers($actor, $target->getAllPermissions()->pluck('name')->all());
    }

    /**
     * Nobody deactivates their own account (prevents locking the agency out).
     */
    public function deactivate(User $actor, User $target): bool
    {
        return $actor->isNot($target) && $this->update($actor, $target);
    }

    public function assignRole(User $actor, string $role): bool
    {
        if (! $actor->can(Permissions::USERS_MANAGE) || in_array($role, Permissions::protectedRoles(), true)) {
            return false;
        }

        $model = Role::query()
            ->where('name', $role)
            ->where('guard_name', 'web')
            ->with('permissions:id,name')
            ->first();

        if ($model === null) {
            return false;
        }

        return $this->covers($actor, $model->permissions->pluck('name')->all());
    }

    /**
     * @param  list<string>  $permissions
     */
    private function covers(User $actor, array $permissions): bool
    {
        $own = $actor->getAllPermissions()->pluck('name')->all();

        return array_diff($permissions, $own) === [];
    }
}
