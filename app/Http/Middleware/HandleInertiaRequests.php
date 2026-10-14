<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Permissions;
use App\Domain\Identity\ViewAs;
use App\Domain\Privacy\PrivacyProgram;
use App\Models\Department;
use App\Models\InboxNotification;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                // True when the user can approve time (owner/lead somewhere, a department lead, or manages workspaces).
                'leads' => fn () => $user !== null && (
                    $user->can(Permissions::WORKSPACES_MANAGE)
                    || DB::table('workspace_user')->where('user_id', $user->id)->whereIn('role', [Workspace::ROLE_OWNER, Workspace::ROLE_LEAD])->exists()
                    || Department::query()->where('lead_user_id', $user->id)->exists()
                ),
                // Department leads see Workload and approve leave for their team (batch 4).
                'leadsDepartment' => fn () => $user !== null && Department::query()->where('lead_user_id', $user->id)->exists(),
                'department' => fn () => $user?->primary_department_id
                    ? Department::query()->whereKey($user->primary_department_id)->first(['id', 'name', 'slug'])?->only(['id', 'name', 'slug'])
                    : null,
            ],
            // E-90: banner while a super admin views the app as this person.
            'viewAs' => fn () => $request->attributes->get('view_as') === true && $user !== null ? [
                'name' => $user->name,
                'expires_at' => app(ViewAs::class)->state($request)['expires_at'] ?? null,
                'reason' => app(ViewAs::class)->state($request)['reason'] ?? null,
            ] : null,
            // E-99: DPO contact (footer) and the notice to acknowledge (never while viewing as someone).
            'privacy' => fn () => $user !== null ? $this->privacy($request, $user) : null,
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'workspaceNav' => fn () => $user ? $this->workspaceNav($request, $user) : null,
            'runningTimer' => fn () => $user ? $this->runningTimer($user) : null,
            // Bell badge. Reloaded alone (only: ['notifications']) when an alert arrives or on a poll.
            'notifications' => fn () => $user ? [
                'unread' => InboxNotification::query()->forUser($user)->bell()->count(),
            ] : null,
        ];
    }

    /**
     * @return array{dpo: array{name: string|null, email: string|null, phone: string|null}|null, notice: array{version: int, title: string, body: string}|null}
     */
    private function privacy(Request $request, User $user): array
    {
        $program = app(PrivacyProgram::class);
        $p = $program->get();
        $hasDpo = $p['dpo_name'] !== null || $p['dpo_email'] !== null || $p['dpo_phone'] !== null;

        return [
            'dpo' => $hasDpo ? ['name' => $p['dpo_name'], 'email' => $p['dpo_email'], 'phone' => $p['dpo_phone']] : null,
            'notice' => $request->attributes->get('view_as') !== true && $program->needsAcknowledgement($user)
                ? ['version' => $p['version'], 'title' => $p['title'], 'body' => $p['body']] : null,
        ];
    }

    /**
     * The user's running timer, shown in the header on every page.
     *
     * @return array<string, mixed>|null
     */
    private function runningTimer(User $user): ?array
    {
        $entry = TimeEntry::query()
            ->with(['task:id,title', 'workspace:id,slug'])
            ->where('user_id', $user->id)
            ->whereHas('workspace')
            ->running()
            ->latest('id')
            ->first();

        if ($entry === null || $entry->started_at === null) {
            return null;
        }

        return [
            'id' => $entry->id,
            'started_at' => $entry->started_at->toIso8601String(),
            'task' => $entry->task?->only(['id', 'title']),
            'workspace_slug' => $entry->workspace->slug,
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
