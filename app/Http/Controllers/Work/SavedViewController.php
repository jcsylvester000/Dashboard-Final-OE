<?php

namespace App\Http\Controllers\Work;

use App\Http\Controllers\Controller;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Save the current task filters + grouping as a named view (personal or shared).
 */
class SavedViewController extends Controller
{
    /** Filter keys a view may store (same names as the list/board query string). */
    public const KEYS = ['search', 'status', 'department', 'project', 'label', 'assignee', 'due', 'type', 'sprint', 'group'];

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('view', $workspace);
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'page' => ['required', Rule::in(SavedView::PAGES)],
            'filters' => ['present', 'array:'.implode(',', self::KEYS)],
            'filters.*' => ['nullable', 'string', 'max:100'],
            'shared' => ['boolean'],
        ]);

        // Only people who work on the client publish views to the whole team.
        $shared = (bool) ($data['shared'] ?? false);
        abort_if($shared && ! Gate::allows('contribute', $workspace), 403);

        if (SavedView::query()->where('workspace_id', $workspace->id)->where('user_id', $user->id)->count() >= 30) {
            return back()->withErrors(['name' => __('You have 30 saved views on this client. Delete one first.')]);
        }

        $view = SavedView::query()->updateOrCreate(
            ['workspace_id' => $workspace->id, 'user_id' => $user->id, 'name' => $data['name']],
            [
                'page' => $data['page'],
                'filters' => array_filter((array) $data['filters'], fn ($v) => $v !== null && $v !== ''),
                'shared' => $shared,
            ],
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => $view->shared
            ? __('View ":name" saved and shared with the team.', ['name' => $view->name])
            : __('View ":name" saved for you.', ['name' => $view->name])]);

        return back();
    }

    public function destroy(Request $request, Workspace $workspace, SavedView $view): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($view->user_id === $user->id || ($view->shared && Gate::allows('lead', $workspace)), 403);

        $view->delete();

        return back();
    }
}
