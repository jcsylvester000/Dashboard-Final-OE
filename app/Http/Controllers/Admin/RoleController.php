<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Identity\Permissions;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permission matrix: which global role can do what.
 * Super Admin always has everything and is not editable here.
 */
class RoleController extends Controller
{
    public function index(): Response
    {
        $labels = Permissions::roles();

        $roles = Role::with('permissions:id,name')
            ->withCount('users')
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $labels[$role->name]['label'] ?? $role->name,
                'users' => $role->users_count,
                'locked' => in_array($role->name, Permissions::protectedRoles(), true),
                'permissions' => $role->permissions->pluck('name')->values(),
            ]);

        $permissions = collect(Permissions::all())
            ->map(fn (string $label, string $name) => ['name' => $name, 'label' => $label])
            ->values();

        return Inertia::render('admin/roles/Index', [
            'roles' => $roles,
            'permissions' => $permissions,
        ]);
    }

    public function update(Request $request, Role $role, ActivityLogger $activity): RedirectResponse
    {
        abort_if(in_array($role->name, Permissions::protectedRoles(), true), 403, 'This role cannot be edited.');

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(Permissions::all()))],
        ]);

        $before = $role->permissions()->pluck('name')->all();

        // No-escalation rule: only a Super Admin may grant a permission they don't hold themselves.
        /** @var User $actor */
        $actor = $request->user();
        if (! $actor->isSuperAdmin()) {
            $own = $actor->getAllPermissions()->pluck('name')->all();
            abort_unless(array_diff(array_diff($data['permissions'], $before), $own) === [], 403, 'You can only grant permissions you have yourself.');
        }

        $role->syncPermissions($data['permissions']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $activity->log('role.permissions-updated', $role, [
            'added' => array_values(array_diff($data['permissions'], $before)),
            'removed' => array_values(array_diff($before, $data['permissions'])),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Permissions saved for :role.', ['role' => $role->name])]);

        return back();
    }
}
