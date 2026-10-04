<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Workspaces\LinkService;
use App\Http\Controllers\Controller;
use App\Models\RecordLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Cross-references between records ("relates to"), with backlinks.
 */
class RecordLinkController extends Controller
{
    public function __construct(
        private readonly LinkService $links,
        private readonly ActivityLogger $activity,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'source_type' => ['required', Rule::in(LinkService::TYPES)],
            'source_id' => ['required', 'integer'],
            'target_type' => ['required', Rule::in(LinkService::TYPES)],
            'target_id' => ['required', 'integer'],
        ]);

        $source = $this->links->resolve($data['source_type'], (int) $data['source_id']);
        $target = $this->links->resolve($data['target_type'], (int) $data['target_id']);
        abort_if($source === null || $target === null, 404);

        // You need contribute rights where the link is created, and view rights on what it points to.
        Gate::authorize('contribute', $this->links->owningWorkspace($source));
        Gate::authorize('view', $target);

        if ($source->is($target)) {
            throw ValidationException::withMessages(['target_id' => __('A record cannot link to itself.')]);
        }

        RecordLink::firstOrCreate([
            'source_type' => $source->getMorphClass(),
            'source_id' => $source->getKey(),
            'target_type' => $target->getMorphClass(),
            'target_id' => $target->getKey(),
        ], ['created_by' => $request->user()->id]);

        $this->activity->log('link.created', $source, [
            'target' => $target->getMorphClass().':'.$target->getKey(),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reference added.')]);

        return back();
    }

    public function destroy(RecordLink $link): RedirectResponse
    {
        $source = $link->source;
        abort_if($source === null, 404);

        Gate::authorize('contribute', $this->links->owningWorkspace($source));

        $link->delete();
        $this->activity->log('link.deleted', $source);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reference removed.')]);

        return back();
    }

    /**
     * Reference picker search: workspaces and projects the user can view.
     */
    public function search(Request $request): JsonResponse
    {
        $term = (string) $request->validate(['q' => ['required', 'string', 'min:2', 'max:80']])['q'];

        return response()->json(['results' => $this->links->search($request->user(), $term)]);
    }
}
