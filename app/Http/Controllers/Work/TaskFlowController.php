<?php

namespace App\Http\Controllers\Work;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Work\CommentService;
use App\Domain\Work\HandoffService;
use App\Domain\Work\ReviewService;
use App\Domain\Work\TaskService;
use App\Domain\Work\TemplateService;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * Reviewer sign-off on a task that is In Review: approve (-> Done) or request
     * changes (-> In Progress, with a required note posted to the discussion).
     */
    public function review(Request $request, Workspace $workspace, Task $task, TaskService $tasks, CommentService $comments): RedirectResponse
    {
        Gate::authorize('review', $task);

        $data = $request->validate([
            'decision' => ['required', Rule::in(['approve', 'changes'])],
            'note' => ['nullable', 'required_if:decision,changes', 'string', 'max:5000'],
        ]);

        if ($task->status_id !== TaskStatus::idFor('in-review')) {
            throw ValidationException::withMessages(['decision' => __('Only tasks that are In Review can be approved or sent back.')]);
        }

        /** @var User $user */
        $user = $request->user();
        $note = trim((string) ($data['note'] ?? ''));

        // E-07: the decision and the status change succeed or fail together, so an approval
        // blocked by the Done gate (open checklist, unapproved files) is not left recorded.
        return DB::transaction(fn () => $this->decide($task, $user, $data['decision'], $note, $tasks, $comments));
    }

    private function decide(Task $task, User $user, string $decision, string $note, TaskService $tasks, CommentService $comments): RedirectResponse
    {
        // E-38: the decision logic lives in ReviewService so the inbox can approve too.
        $message = app(ReviewService::class)->decide($task, $user, $decision, $note);
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }

    /**
     * E-07: who reviews this task and whether all of them or any one must approve.
     */
    public function reviewers(Request $request, Workspace $workspace, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);
        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:10'],
            'user_ids.*' => ['integer'],
            'rule' => ['required', Rule::in(['all', 'any'])],
        ]);
        /** @var User $user */
        $user = $request->user();
        app(ReviewService::class)->setReviewers($task, array_map('intval', $data['user_ids']), (string) $data['rule'], $user);

        return back();
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
