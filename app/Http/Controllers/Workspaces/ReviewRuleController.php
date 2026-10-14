<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\ReviewRule;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * E-34: a client's review rules (leads). Changes apply to the next submissions only.
 */
class ReviewRuleController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function store(Request $request, Workspace $workspace, WorkspaceAccess $access): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        $deliverables = Task::WORK_DETAIL_FIELDS['marketing']['deliverable_type'][2] ?? [];
        $data = $request->validate([
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('workspace_id', $workspace->id)],
            'deliverable_type' => ['nullable', Rule::in($deliverables)],
            'task_type' => ['nullable', Rule::in(array_keys(Task::TYPES))],
            'user_ids' => ['required', 'array', 'min:1', 'max:10'],
            'user_ids.*' => ['integer'],
            'mode' => ['required', Rule::in(array_keys(ReviewRule::MODES))],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['user_ids'])));
        foreach ($ids as $id) {
            $user = User::query()->active()->find($id);
            if ($user === null || ! $access->canContribute($user, $workspace)) {
                throw ValidationException::withMessages(['user_ids' => __('Reviewers must be active members of this client.')]);
            }
        }
        /** @var User $by */
        $by = $request->user();
        $rule = ReviewRule::query()->create([...$data, 'workspace_id' => $workspace->id, 'user_ids' => $ids, 'created_by' => $by->id]);
        $this->activity->log('workspace.review-rule-added', $workspace, ['rule_id' => $rule->id, 'mode' => $rule->mode]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Review rule added. It fills reviewers on the next submissions.')]);

        return back();
    }

    public function destroy(Workspace $workspace, ReviewRule $reviewRule): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_unless($reviewRule->workspace_id === $workspace->id, 404);
        $reviewRule->delete();
        $this->activity->log('workspace.review-rule-removed', $workspace, ['rule_id' => $reviewRule->id]);

        return back();
    }
}
