<?php

namespace App\Http\Controllers\Work;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Work\HandoffService;
use App\Domain\Work\TemplateService;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Cross-department flow: handoffs, "waiting on" dependencies and workflow templates.
 */
class TaskFlowController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function handoff(Request $request, Workspace $workspace, Task $task, HandoffService $handoffs): RedirectResponse
    {
        Gate::authorize('update', $task);

        $data = $request->validate([
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'assignee_id' => ['nullable', 'integer', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id)],
            'title' => ['nullable', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:5000'],
            'due_on' => ['nullable', 'date'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $next = $handoffs->handoff($task, $data, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Handed off. The new task waits until this one is done.')]);

        return to_route('workspaces.tasks.show', [$workspace, $next]);
    }

    public function addDependency(Request $request, Workspace $workspace, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);

        $data = $request->validate([
            'depends_on_task_id' => [
                'required', 'integer',
                Rule::exists('tasks', 'id')->where('workspace_id', $workspace->id)->whereNull('deleted_at'),
            ],
        ]);
        $otherId = (int) $data['depends_on_task_id'];

        if ($otherId === $task->id) {
            throw ValidationException::withMessages(['depends_on_task_id' => __('A task cannot wait on itself.')]);
        }

        // Refuse direct cycles (A waits on B while B waits on A).
        $cycle = Task::query()->whereKey($otherId)
            ->whereHas('dependencies', fn ($q) => $q->whereKey($task->id))
            ->exists();
        if ($cycle) {
            throw ValidationException::withMessages(['depends_on_task_id' => __('That task is already waiting on this one.')]);
        }

        $task->dependencies()->syncWithoutDetaching([
            $otherId => ['type' => 'blocks', 'created_by' => $request->user()->id],
        ]);
        $this->activity->log('task.dependency-added', $task, ['depends_on_task_id' => $otherId]);

        return back();
    }

    public function removeDependency(Workspace $workspace, Task $task, Task $dependency): RedirectResponse
    {
        Gate::authorize('update', $task);

        $task->dependencies()->detach($dependency->id);
        $this->activity->log('task.dependency-removed', $task, ['depends_on_task_id' => $dependency->id]);

        return back();
    }

    public function applyTemplate(Request $request, Workspace $workspace, WorkflowTemplate $template, TemplateService $templates): RedirectResponse
    {
        Gate::authorize('contribute', $workspace);
        abort_unless($template->is_active, 404);

        $data = $request->validate([
            'project_id' => ['nullable', 'integer', Rule::exists('projects', 'id')->where('workspace_id', $workspace->id)->whereNull('deleted_at')],
            'start_on' => ['required', 'date'],
            'assignees' => ['array'],
            'assignees.*' => ['nullable', 'integer', Rule::exists('workspace_user', 'user_id')->where('workspace_id', $workspace->id)],
        ]);

        /** @var User $user */
        $user = $request->user();
        $project = isset($data['project_id']) ? Project::find($data['project_id']) : null;

        $created = $templates->apply(
            $template,
            $workspace,
            $project,
            CarbonImmutable::parse($data['start_on']),
            $user,
            array_map(fn ($id) => $id === null ? null : (int) $id, $data['assignees'] ?? []),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':n tasks created from ":t".', ['n' => count($created), 't' => $template->name])]);

        return to_route('workspaces.tasks.board', $workspace);
    }
}
