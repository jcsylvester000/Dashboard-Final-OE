<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\Label;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Workspace colour labels. Owners and leads manage them; anyone who can
 * contribute can attach them to projects.
 */
class LabelController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('lead', $workspace);

        $data = $this->validated($request, $workspace);
        $label = $workspace->labels()->create($data);

        $this->activity->log('label.created', $workspace, ['label' => $label->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Label added.')]);

        return back();
    }

    public function update(Request $request, Workspace $workspace, Label $label): RedirectResponse
    {
        Gate::authorize('lead', $workspace);

        $label->fill($this->validated($request, $workspace, $label))->save();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Label saved.')]);

        return back();
    }

    public function destroy(Workspace $workspace, Label $label): RedirectResponse
    {
        Gate::authorize('lead', $workspace);

        $name = $label->name;
        $label->delete(); // labelables rows cascade

        $this->activity->log('label.deleted', $workspace, ['label' => $name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Label removed.')]);

        return back();
    }

    /**
     * @return array{name: string, color: string}
     */
    private function validated(Request $request, Workspace $workspace, ?Label $label = null): array
    {
        /** @var array{name: string, color: string} */
        return $request->validate([
            'name' => [
                'required', 'string', 'max:50',
                Rule::unique('labels', 'name')->where('workspace_id', $workspace->id)->ignore($label?->id),
            ],
            'color' => ['required', Rule::in(Workspace::COLORS)],
        ]);
    }
}
