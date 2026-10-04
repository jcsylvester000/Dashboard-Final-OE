<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\ProjectResource;
use App\Http\Resources\V1\UserResource;
use App\Http\Resources\V1\WorkspaceResource;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Client workspaces the token owner can open (same rules as the web app).
 */
class WorkspaceController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return WorkspaceResource::collection(
            Workspace::query()->visibleTo($request->user())->orderBy('name')->paginate($this->perPage($request))
        );
    }

    public function show(Workspace $workspace): WorkspaceResource
    {
        Gate::authorize('view', $workspace);

        return new WorkspaceResource($workspace);
    }

    public function members(Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('view', $workspace);

        return UserResource::collection($workspace->members()->where('users.is_active', true)->orderBy('name')->get());
    }

    public function projects(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        Gate::authorize('view', $workspace);

        return ProjectResource::collection($workspace->projects()->orderBy('name')->paginate($this->perPage($request)));
    }

    private function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 50)));
    }
}
