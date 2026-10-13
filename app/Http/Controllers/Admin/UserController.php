<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\AccessLinkService;
use App\Domain\Identity\ActivityLogger;
use App\Domain\Identity\Offboarding;
use App\Domain\Identity\Permissions;
use App\Domain\Identity\PersonalData;
use App\Domain\Identity\UserAccessManager;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveUserRequest;
use App\Models\Department;
use App\Models\User;
use App\Models\UserAccessLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function __construct(
        private readonly AccessLinkService $links,
        private readonly UserAccessManager $access,
        private readonly ActivityLogger $activity,
        private readonly Offboarding $offboarding,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'role' => ['nullable', 'string', Rule::exists('roles', 'name')],
            'department' => ['nullable', 'integer'],
        ]);

        $users = User::query()
            ->with(['roles:id,name', 'primaryDepartment:id,name,color'])
            ->when($filters['search'] ?? null, function (Builder $q, string $term) {
                $like = '%'.mb_strtolower($term).'%';
                $q->where(fn (Builder $w) => $w
                    ->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(email) like ?', [$like])
                    ->orWhereRaw('lower(title) like ?', [$like]));
            })
            ->when(($filters['status'] ?? null) === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when(($filters['status'] ?? null) === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->when($filters['role'] ?? null, fn (Builder $q, string $role) => $q->role($role))
            ->when($filters['department'] ?? null, fn (Builder $q, $id) => $q->where(fn (Builder $w) => $w
                ->where('primary_department_id', (int) $id)
                ->orWhereHas('departments', fn (Builder $d) => $d->whereKey((int) $id))))
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'title' => $u->title,
                'is_active' => $u->is_active,
                'role' => $u->getRoleNames()->first(),
                'department' => $u->primaryDepartment?->only(['id', 'name', 'color']),
                'two_factor' => $u->two_factor_confirmed_at !== null,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ]);

        return Inertia::render('admin/users/Index', [
            'users' => $users,
            'filters' => $filters,
            'roles' => $this->roleOptions(),
            'departments' => $this->departmentOptions(),
            'canManage' => $request->user()->can(Permissions::USERS_MANAGE),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/users/Create', [
            'roles' => $this->roleOptions(),
            'departments' => $this->departmentOptions(),
        ]);
    }

    public function store(SaveUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = DB::transaction(function () use ($data) {
            $user = new User;
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'title' => $data['title'] ?? null,
                'primary_department_id' => $data['primary_department_id'] ?? null,
                // Unusable random password until the member opens their setup link.
                'password' => bin2hex(random_bytes(32)),
            ]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->syncRoles([$data['role']]);
            $user->departments()->sync($this->departmentIds($data));

            return $user;
        });

        $this->activity->log('user.created', $user, ['role' => $data['role']]);

        ['url' => $url, 'link' => $link] = $this->links->issue($user, UserAccessLink::PURPOSE_SETUP, $request->user());

        return to_route('admin.users.edit', $user)->with('issuedSecret', [
            'type' => 'link',
            'purpose' => UserAccessLink::PURPOSE_SETUP,
            'value' => $url,
            'expiresAt' => $link->expires_at->toIso8601String(),
        ]);
    }

    public function edit(Request $request, User $user): Response
    {
        Gate::authorize('update', $user);

        // A one-time link or password may be in the props: keep it out of plain browser history.
        if ($request->session()->has('issuedSecret')) {
            Inertia::encryptHistory();
        }

        $user->load(['roles:id,name', 'departments:id']);

        return Inertia::render('admin/users/Edit', [
            'member' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'title' => $user->title,
                'role' => $user->getRoleNames()->first(),
                'primary_department_id' => $user->primary_department_id,
                'department_ids' => $user->departments->pluck('id')->values(),
                'is_active' => $user->is_active,
                'must_change_password' => $user->must_change_password,
                'two_factor' => $user->two_factor_confirmed_at !== null,
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
                'is_self' => $user->is($request->user()),
            ],
            'accessLinks' => $user->accessLinks()
                ->with('creator:id,name')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (UserAccessLink $l) => [
                    'id' => $l->id,
                    'purpose' => $l->purpose,
                    'status' => $l->status(),
                    'created_by' => $l->creator?->name,
                    'created_at' => $l->created_at->toIso8601String(),
                    'expires_at' => $l->expires_at->toIso8601String(),
                    'used_at' => $l->used_at?->toIso8601String(),
                ]),
            'roles' => $this->roleOptions(),
            'departments' => $this->departmentOptions(),
            'handover' => $user->is_active ? $this->offboarding->summary($user) : null,
            'handoverTo' => $user->is_active ? User::query()
                ->where('is_active', true)
                ->whereKeyNot($user->id)
                ->orderBy('name')
                ->get(['id', 'name', 'title'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'title' => $u->title])
                ->values() : [],
            // Shown once, right after it was generated. Never stored in plain text.
            'issuedSecret' => $request->session()->get('issuedSecret'),
        ]);
    }

    public function update(SaveUserRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        // Nobody changes their own role (this also stops the last Super Admin demoting themselves).
        if ($user->is($request->user()) && ! $user->hasRole($data['role'])) {
            throw ValidationException::withMessages(['role' => __('You cannot change your own role. Ask another admin.')]);
        }

        $before = ['email' => $user->email, 'role' => $user->getRoleNames()->first()];

        DB::transaction(function () use ($user, $data) {
            $user->fill([
                'name' => $data['name'],
                'email' => $data['email'],
                'title' => $data['title'] ?? null,
                'primary_department_id' => $data['primary_department_id'] ?? null,
            ])->save();

            $user->syncRoles([$data['role']]);
            $user->departments()->sync($this->departmentIds($data));
        });

        $this->activity->log('user.updated', $user, [
            'email_changed' => $before['email'] !== $user->email,
            'role' => ['from' => $before['role'], 'to' => $data['role']],
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Team member updated.')]);

        return to_route('admin.users.edit', $user);
    }

    public function deactivate(Request $request, User $user): RedirectResponse
    {
        abort_if($user->is($request->user()), 422, 'You cannot deactivate your own account.');
        Gate::authorize('deactivate', $user);

        $data = $request->validate([
            'reassign_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereNot('id', $user->id)],
        ]);
        $to = isset($data['reassign_to']) ? User::query()->findOrFail($data['reassign_to']) : null;

        // Hand over first: running timers are stopped and saved before sign-out.
        $moved = $this->offboarding->handOver($user, $to, $request->user());
        $this->access->deactivate($user, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was deactivated and signed out. :tasks open tasks moved, :unassigned left unassigned, :timers timers stopped.', [
            'name' => $user->name,
            'tasks' => $moved['tasks_reassigned'],
            'unassigned' => $moved['tasks_unassigned'],
            'timers' => $moved['timers_stopped'],
        ])]);

        return back();
    }

    /**
     * Data Privacy Act access request: everything held about the person, as a JSON download.
     */
    public function exportData(Request $request, User $user, PersonalData $data): JsonResponse
    {
        Gate::authorize('update', $user);

        $filename = 'personal-data-'.$user->id.'-'.now()->format('Y-m-d').'.json';

        return response()->json($data->export($user, $request->user()), 200, [
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Erasure request for a deactivated person. The admin retypes the name to confirm.
     */
    public function anonymise(Request $request, User $user, PersonalData $data): RedirectResponse
    {
        Gate::authorize('update', $user);
        $request->validate(['confirm_name' => ['required', 'string', Rule::in([$user->name])]], [
            'confirm_name.in' => __('Type the person\'s full name exactly to confirm.'),
        ]);

        $data->anonymise($user, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Personal details erased. Work records stay under "Former member #:id".', ['id' => $user->id])]);

        return back();
    }

    public function reactivate(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);
        abort_if(str_ends_with($user->email, '@anonymised.invalid'), 422, 'This account was anonymised and cannot be reactivated.');

        $this->access->reactivate($user, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was reactivated.', ['name' => $user->name])]);

        return back();
    }

    public function issueAccessLink(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);
        abort_unless($user->is_active, 422, 'Reactivate the account first.');

        $purpose = $user->last_login_at === null ? UserAccessLink::PURPOSE_SETUP : UserAccessLink::PURPOSE_RESET;
        ['url' => $url, 'link' => $link] = $this->links->issue($user, $purpose, $request->user());

        return back()->with('issuedSecret', [
            'type' => 'link',
            'purpose' => $purpose,
            'value' => $url,
            'expiresAt' => $link->expires_at->toIso8601String(),
        ]);
    }

    public function temporaryPassword(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);
        abort_if($user->is($request->user()), 422, 'Use Settings > Security to change your own password.');
        abort_unless($user->is_active, 422, 'Reactivate the account first.');

        $password = $this->access->issueTemporaryPassword($user, $request->user());

        return back()->with('issuedSecret', [
            'type' => 'password',
            'purpose' => 'temporary',
            'value' => $password,
            'expiresAt' => null,
        ]);
    }

    public function resetTwoFactor(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);

        $this->access->resetTwoFactor($user, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Two-factor authentication was reset.')]);

        return back();
    }

    public function signOut(Request $request, User $user): RedirectResponse
    {
        Gate::authorize('update', $user);
        abort_if($user->is($request->user()), 422, 'Use Log out to end your own session.');

        $this->access->signOutEverywhere($user, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name was signed out of every device.', ['name' => $user->name])]);

        return back();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<int>
     */
    private function departmentIds(array $data): array
    {
        $ids = array_map('intval', $data['department_ids'] ?? []);
        if (! empty($data['primary_department_id'])) {
            $ids[] = (int) $data['primary_department_id'];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function roleOptions(): array
    {
        $labels = array_map(fn (array $r) => $r['label'], Permissions::roles());

        // Only offer roles the current admin is allowed to grant.
        return Role::query()->orderBy('id')->pluck('name')
            ->filter(fn (string $name) => Gate::allows('assignRole', [User::class, $name]))
            ->map(fn (string $name) => ['value' => $name, 'label' => $labels[$name] ?? $name])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, color: string}>
     */
    private function departmentOptions(): array
    {
        return Department::query()->orderBy('position')->get(['id', 'name', 'color'])
            ->map(fn (Department $d) => ['id' => $d->id, 'name' => $d->name, 'color' => $d->color])
            ->all();
    }
}
