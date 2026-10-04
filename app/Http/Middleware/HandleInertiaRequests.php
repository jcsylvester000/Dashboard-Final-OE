<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Permissions;
use App\Models\Department;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Props shared with every page. Keep this small: it ships on every request.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        /** @var User|null $user */
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'roles' => fn () => $user?->getRoleNames()->values()->all() ?? [],
                'can' => fn () => $this->abilities($user),
                'department' => fn () => $user?->primary_department_id
                    ? Department::query()->whereKey($user->primary_department_id)->first(['id', 'name', 'slug'])?->only(['id', 'name', 'slug'])
                    : null,
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'workspaceNav' => fn () => $user ? $this->workspaceNav($request, $user) : null,
        ];
    }

    /**
     * Data for the sidebar workspace switcher: the workspaces the user can open
     * and the one currently in focus (route parameter, else last visited).
     *
     * @return array{current: array<string, mixed>|null, items: list<array<string, mixed>>}
     */
    private function workspaceNav(Request $request, User $user): array
    {
        $items = Workspace::query()
            ->visibleTo($user)
            ->where('status', '!=', 'archived')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'name', 'slug', 'color'])
            ->map(fn (Workspace $w) => ['id' => $w->id, 'name' => $w->name, 'slug' => $w->slug, 'color' => $w->color])
            ->values()
            ->all();

        $routeWorkspace = $request->route('workspace');
        $currentId = $routeWorkspace instanceof Workspace ? $routeWorkspace->id : $user->last_workspace_id;

        $current = collect($items)->firstWhere('id', $currentId);
        if ($current === null && $routeWorkspace instanceof Workspace) {
            $current = ['id' => $routeWorkspace->id, 'name' => $routeWorkspace->name, 'slug' => $routeWorkspace->slug, 'color' => $routeWorkspace->color];
        }

        return ['current' => $current, 'items' => $items];
    }

    /**
     * Map of permission => bool the UI uses to show or hide controls.
     * The server still authorizes every request; this is only for display.
     *
     * @return array<string, bool>
     */
    private function abilities(?User $user): array
    {
        if ($user === null) {
            return [];
        }

        $abilities = [];
        foreach (array_keys(Permissions::all()) as $permission) {
            $abilities[$permission] = $user->can($permission);
        }

        return $abilities;
    }
}
