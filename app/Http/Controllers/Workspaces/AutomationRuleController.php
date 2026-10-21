<?php

namespace App\Http\Controllers\Workspaces;

use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\AutomationRule;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * E-37: a client's automation rules (leads). Turning a rule off keeps its run log.
 */
class AutomationRuleController extends Controller
{
    public const MAX = 25;

    public function __construct(private readonly ActivityLogger $activity) {}

    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_if(AutomationRule::query()->where('workspace_id', $workspace->id)->count() >= self::MAX, 422, 'Up to '.self::MAX.' rules per client.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'trigger' => ['required', Rule::in(array_keys(AutomationRule::TRIGGERS))],
            'trigger_value' => ['nullable', 'string', 'max:60'],
            'conditions' => ['array'],
            'conditions.department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'conditions.type' => ['nullable', Rule::in(array_keys(Task::TYPES))],
            'conditions.project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('workspace_id', $workspace->id)],
            'actions' => ['required', 'array', 'min:1', 'max:5'],
            'actions.*.type' => ['required', Rule::in(array_keys(AutomationRule::ACTIONS))],
            'actions.*.value' => ['nullable', 'string', 'max:200'],
        ]);
        if ($data['trigger'] === 'status_changed' && ! in_array($data['trigger_value'] ?? null, TaskStatus::ordered()->pluck('slug')->all(), true)) {
            return back()->withErrors(['trigger_value' => __('Pick the status that starts this rule.')]);
        }
        if ($data['trigger'] === 'label_added' && ! $workspace->labels()->whereKey((int) ($data['trigger_value'] ?? 0))->exists()) {
            return back()->withErrors(['trigger_value' => __('Pick the label that starts this rule.')]);
        }
        /** @var User $user */
        $user = $request->user();
        $rule = AutomationRule::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $data['name'],
            'trigger' => $data['trigger'],
            'trigger_value' => in_array($data['trigger'], ['status_changed', 'label_added'], true) ? $data['trigger_value'] : null,
            'conditions' => array_filter((array) ($data['conditions'] ?? []), fn ($v) => $v !== null && $v !== ''),
            'actions' => array_values(array_map(fn (array $a) => ['type' => (string) $a['type'], 'value' => isset($a['value']) ? (string) $a['value'] : null], (array) $data['actions'])),
            'enabled' => true,
            'created_by' => $user->id,
        ]);
        $this->activity->log('workspace.rule-added', $workspace, ['rule_id' => $rule->id, 'name' => $rule->name]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Rule ":n" is on.', ['n' => $rule->name])]);

        return back();
    }

    public function update(Request $request, Workspace $workspace, AutomationRule $rule): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_unless($rule->workspace_id === $workspace->id, 404);
        /** @var User $user */
        $user = $request->user();
        // Actions run as whoever turned the rule on last.
        $rule->forceFill(['enabled' => $request->boolean('enabled'), 'created_by' => $user->id])->save();

        return back();
    }

    public function destroy(Workspace $workspace, AutomationRule $rule): RedirectResponse
    {
        Gate::authorize('lead', $workspace);
        abort_unless($rule->workspace_id === $workspace->id, 404);
        $rule->delete();
        $this->activity->log('workspace.rule-removed', $workspace, ['rule_id' => $rule->id]);

        return back();
    }
}
