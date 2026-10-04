<?php

namespace App\Http\Middleware;

use App\Domain\Identity\Permissions;
use App\Models\User;
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
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
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
