<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\DeliverySpec;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * E-18: a client's delivery specs (leads). Uploads are checked against them; the check
 * is computed live, so editing a spec re-checks every video at once.
 */
class DeliverySpecController extends Controller
{
    public const MAX = 30;

    public function __construct(private readonly ActivityLogger $activity) {}

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_if(DeliverySpec::query()->where('workspace_id', $workspace->id)->count() >= self::MAX, 422, 'Up to '.self::MAX.' specs per client.');
        /** @var User $by */
        $by = $request->user();
        $spec = DeliverySpec::query()->create([...$this->validated($request), 'workspace_id' => $workspace->id, 'created_by' => $by->id]);
        $this->activity->log('workspace.delivery-spec-added', $workspace, ['spec_id' => $spec->id, 'name' => $spec->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Spec ":n" added. Matching videos are checked against it.', ['n' => $spec->name])]);

        return back();
    }

    public function update(Request $request, Workspace $workspace, DeliverySpec $deliverySpec): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_unless($deliverySpec->workspace_id === $workspace->id, 404);
        $deliverySpec->update($this->validated($request));
        $this->activity->log('workspace.delivery-spec-updated', $workspace, ['spec_id' => $deliverySpec->id]);

        return back();
    }

    public function destroy(Workspace $workspace, DeliverySpec $deliverySpec): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_unless($deliverySpec->workspace_id === $workspace->id, 404);
        $deliverySpec->delete();
        $this->activity->log('workspace.delivery-spec-removed', $workspace, ['spec_id' => $deliverySpec->id]);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'channel' => ['nullable', Rule::in(Task::WORK_DETAIL_FIELDS['marketing']['channel'][2])],
            'deliverable' => ['nullable', Rule::in(Task::WORK_DETAIL_FIELDS['marketing']['deliverable_type'][2])],
            'aspect' => ['nullable', 'regex:/^\d{1,3}:\d{1,3}$/'],
            'min_width' => ['nullable', 'integer', 'between:16,8192'],
            'min_height' => ['nullable', 'integer', 'between:16,8192'],
            'max_duration_s' => ['nullable', 'integer', 'between:1,86400'],
            'max_size_mb' => ['nullable', 'integer', 'between:1,102400'],
            'captions_required' => ['boolean'],
        ]);
        $data['captions_required'] = (bool) ($data['captions_required'] ?? false);

        return $data;
    }
}
