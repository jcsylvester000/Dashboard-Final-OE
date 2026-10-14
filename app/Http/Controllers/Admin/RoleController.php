<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Identity\Permissions;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permission matrix: which global role can do what. Super Admin always has everything
 * and is not editable here.
 *
 * E-89: create, copy and delete roles (built-in roles can't be deleted; a role still
 * held by people can't be deleted until they are moved), plain-language permission
 * help, umbrella permissions, and nobody can remove their own access to this page.
 */
class RoleController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function index(): Response
    {
        $roles = Role::with('permissions:id,name')
            ->withCount('users')
            ->orderBy('id')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'label' => self::label($role),
                'description' => $role->getAttribute('description'),
                'users' => $role->users_count,
                'locked' => in_array($role->name, Permissions::protectedRoles(), true),
                'built_in' => in_array($role->name, Permissions::builtInRoles(), true),
                'permissions' => $role->permissions->pluck('name')->values(),
            ]);

        $descriptions = Permissions::descriptions();
        $permissions = collect(Permissions::all())
            ->map(fn (string $label, string $name) => [
                'name' => $name,
                'label' => $label,
                'description' => $descriptions[$name] ?? null,
                'parent' => Permissions::parentOf($name),
                'requires' => Permissions::REQUIRES[$name] ?? null,
            ])
            ->values();

        return Inertia::render('admin/roles/Index', [
            'roles' => $roles,
            'permissions' => $permissions,
            'groups' => Permissions::groups(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:255'],
            'copy_from' => ['nullable', 'integer', Rule::exists((string) config('permission.table_names.roles', 'roles'), 'id')],
        ]);
        $name = $this->uniqueName((string) $data['label']);
        $source = isset($data['copy_from']) ? Role::query()->with('permissions:id,name')->findOrFail((int) $data['copy_from']) : null;
        abort_if($source !== null && in_array($source->name, Permissions::protectedRoles(), true), 403, 'Super Admin cannot be copied.');
        $permissions = $source?->permissions->pluck('name')->all() ?? [];
        $this->assertNoEscalation($request, $permissions, []);

        /** @var Role $role */
        $role = Role::create(['name' => $name, 'guard_name' => 'web']);
        $role->forceFill(['label' => trim((string) $data['label']), 'description' => $data['description'] ?? null])->save();
        $role->syncPermissions($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->activity->log('role.created', $role, ['label' => $role->getAttribute('label'), 'copied_from' => $source?->name, 'permissions' => $permissions]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role ":r" created.', ['r' => $role->getAttribute('label')])]);

        return back();
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        abort_if(in_array($role->name, Permissions::protectedRoles(), true), 403, 'This role cannot be edited.');

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(Permissions::all()))],
            'label' => ['sometimes', 'required', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Each billing part needs billing.view to open the area: add it rather than fail.
        $permissions = array_values(array_unique($data['permissions']));
        foreach ($permissions as $p) {
            if (isset(Permissions::REQUIRES[$p]) && ! in_array(Permissions::REQUIRES[$p], Permissions::expand($permissions), true)) {
                $permissions[] = Permissions::REQUIRES[$p];
            }
        }

        $before = $role->permissions()->pluck('name')->all();
        $this->assertNoEscalation($request, $permissions, $before);

        // Nobody removes their own access to roles (the agency could lock itself out).
        /** @var User $actor */
        $actor = $request->user();
        if (! $actor->isSuperAdmin() && $actor->hasRole($role->name) && ! in_array(Permissions::ROLES_MANAGE, $permissions, true)) {
            $other = $actor->roles->where('id', '!=', $role->id)->contains(fn ($r) => $r instanceof Role && $r->hasPermissionTo(Permissions::ROLES_MANAGE));
            if (! $other) {
                throw ValidationException::withMessages(['permissions' => __('You would lose access to this page. Ask another admin to change your own role.')]);
            }
        }

        $role->syncPermissions($permissions);
        if (array_key_exists('label', $data)) {
            $role->forceFill(['label' => trim((string) $data['label']), 'description' => $data['description'] ?? $role->getAttribute('description')])->save();
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->activity->log('role.permissions-updated', $role, [
            'added' => array_values(array_diff($permissions, $before)),
            'removed' => array_values(array_diff($before, $permissions)),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Permissions saved for :role.', ['role' => self::label($role)])]);

        return back();
    }

    public function destroy(Role $role): RedirectResponse
    {
        if (in_array($role->name, Permissions::builtInRoles(), true)) {
            throw ValidationException::withMessages(['role' => __('Built-in roles can be changed but not deleted.')]);
        }
        $holders = $role->users()->count();
        if ($holders > 0) {
            throw ValidationException::withMessages(['role' => trans_choice('{1} 1 person still has this role. Give them another role first.|[2,*] :count people still have this role. Give them another role first.', $holders, ['count' => $holders])]);
        }
        $label = self::label($role);
        $role->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->activity->log('role.deleted', null, ['name' => $role->name, 'label' => $label]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role ":r" deleted.', ['r' => $label])]);

        return back();
    }

    /** Display name: the saved label, the built-in label, or the key. */
    public static function label(Role $role): string
    {
        $saved = $role->getAttribute('label');

        return is_string($saved) && $saved !== '' ? $saved : (Permissions::roles()[$role->name]['label'] ?? Str::headline($role->name));
    }

    /**
     * Only a Super Admin may grant a permission (or umbrella) they don't hold themselves.
     *
     * @param  list<string>  $permissions
     * @param  list<string>  $before
     */
    private function assertNoEscalation(Request $request, array $permissions, array $before): void
    {
        /** @var User $actor */
        $actor = $request->user();
        if ($actor->isSuperAdmin()) {
            return;
        }
        $own = Permissions::expand($actor->getAllPermissions()->pluck('name')->all());
        $added = array_diff(Permissions::expand($permissions), Permissions::expand($before));
        abort_unless(array_diff($added, $own) === [], 403, 'You can only grant permissions you have yourself.');
    }

    private function uniqueName(string $label): string
    {
        $base = Str::slug($label) ?: 'role';
        $name = $base;
        for ($n = 2; Role::query()->where('name', $name)->where('guard_name', 'web')->exists(); $n++) {
            $name = $base.'-'.$n;
        }

        return $name;
    }
}
