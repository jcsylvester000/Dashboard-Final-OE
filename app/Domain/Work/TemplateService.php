<?php

namespace App\Domain\Work;

use App\Domain\Identity\ActivityLogger;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns a workflow template into real tasks: one per step, in the step's
 * department, due on start + offset, each waiting on the previous step.
 */
class TemplateService
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array<int, int|null>  $assigneesByDepartment  department_id => user_id
     * @return list<Task>
     */
    public function apply(
        WorkflowTemplate $template,
        Workspace $workspace,
        ?Project $project,
        CarbonImmutable $startOn,
        User $actor,
        array $assigneesByDepartment = [],
    ): array {
        $template->loadMissing('steps');

        return DB::transaction(function () use ($template, $workspace, $project, $startOn, $actor, $assigneesByDepartment) {
            $created = [];
            $previous = null;

            foreach ($template->steps as $step) {
                // A step that waits on the previous one starts in Backlog and moves
                // to To Do automatically when the previous step is done.
                $waits = $previous !== null && $step->depends_on_previous;

                $task = $this->tasks->create($workspace, [
                    'project_id' => $project?->id,
                    'department_id' => $step->department_id,
                    'status_id' => TaskStatus::idFor($waits ? 'backlog' : 'todo'),
                    'title' => $step->title,
                    'description' => $step->description,
                    'assignee_id' => $step->department_id !== null ? ($assigneesByDepartment[$step->department_id] ?? null) : null,
                    'due_on' => $startOn->addDays($step->offset_days)->toDateString(),
                ], $actor);

                if ($waits) {
                    $task->dependencies()->syncWithoutDetaching([
                        $previous->id => ['type' => 'handoff', 'created_by' => $actor->id],
                    ]);
                    $task->forceFill(['handoff_from_task_id' => $previous->id])->save();
                }

                $created[] = $task;
                $previous = $task;
            }

            $this->activity->log('workflow.applied', $workspace, [
                'template' => $template->name,
                'project_id' => $project?->id,
                'tasks' => count($created),
            ], $actor);

            return $created;
        });
    }
}
