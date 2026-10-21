<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Documents\ReportPacks;
use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\ReportPack;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * E-51: scheduled report packs for a client (leads): what goes in, how often, who hears.
 */
class ReportPackController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $data = $this->validated($request, $workspace);
        /** @var User $user */
        $user = $request->user();
        $pack = ReportPack::query()->create([...$data, 'workspace_id' => $workspace->id, 'enabled' => true, 'created_by' => $user->id,
            'next_run_on' => ReportPacks::nextRun($data['frequency'], (int) $data['day'], CarbonImmutable::today())->toDateString()]);
        $this->activity->log('workspace.report-pack-added', $workspace, ['pack_id' => $pack->id, 'name' => $pack->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report pack scheduled. First one on :d.', ['d' => $pack->next_run_on->format('M j')])]);

        return back();
    }

    public function update(Request $request, Workspace $workspace, ReportPack $pack): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_unless($pack->workspace_id === $workspace->id, 404);
        if ($request->has('enabled') && ! $request->has('name')) {
            $pack->forceFill(['enabled' => $request->boolean('enabled')])->save();

            return back();
        }
        $data = $this->validated($request, $workspace);
        $pack->forceFill([...$data, 'next_run_on' => ReportPacks::nextRun($data['frequency'], (int) $data['day'], CarbonImmutable::today())->toDateString()])->save();

        return back();
    }

    public function destroy(Workspace $workspace, ReportPack $pack): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_unless($pack->workspace_id === $workspace->id, 404);
        $pack->delete();

        return back();
    }

    /** Make one now (for a check before the first scheduled run). */
    public function run(Request $request, Workspace $workspace, ReportPack $pack, ReportPacks $packs): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_unless($pack->workspace_id === $workspace->id, 404);
        /** @var User $user */
        $user = $request->user();
        $packs->run($pack, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Report pack saved to the client\'s files.')]);

        return back();
    }

    /**
     * @return array{name: string, sections: list<string>, frequency: string, day: int, recipient_ids: list<int>}
     */
    private function validated(Request $request, Workspace $workspace): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*' => [Rule::in(array_keys(ReportPack::SECTIONS))],
            'frequency' => ['required', Rule::in(['weekly', 'monthly'])],
            'day' => ['required', 'integer', 'min:1', $request->input('frequency') === 'weekly' ? 'max:7' : 'max:28'],
            'recipient_ids' => ['nullable', 'array', 'max:20'],
            'recipient_ids.*' => ['integer', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id)],
        ]);

        return [
            'name' => (string) $data['name'],
            'sections' => array_values(array_unique(array_map('strval', (array) $data['sections']))),
            'frequency' => (string) $data['frequency'],
            'day' => (int) $data['day'],
            'recipient_ids' => array_values(array_unique(array_map('intval', (array) ($data['recipient_ids'] ?? [])))),
        ];
    }
}
