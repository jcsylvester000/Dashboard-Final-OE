<?php

namespace Database\Seeders;

use App\Domain\Identity\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Idempotent: safe to run on every deploy. Adds new permissions/roles;
 * only sets a role's permissions when the role is first created, so edits
 * made in Admin > Roles are kept.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (array_keys(Permissions::all()) as $name) {
            Permission::findOrCreate($name, 'web');
        }

        foreach (Permissions::roles() as $name => $definition) {
            $role = Role::where('name', $name)->where('guard_name', 'web')->first();

            if ($role === null) {
                $role = Role::create(['name' => $name, 'guard_name' => 'web']);
                $role->syncPermissions($definition['permissions']);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
