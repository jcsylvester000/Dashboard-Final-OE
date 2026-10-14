<?php

namespace App\Domain\Work;

use App\Domain\Identity\ActivityLogger;
use App\Domain\People\Capacity;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\WorkflowTemplate;
use App\Models\WorkflowTemplateStep;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Turns a workflow template into real tasks: one per step, in the step's
 * department, due on start + offset, each waiting on the previous step.
 * E-40: steps can name reviewers (or "the client's leads") with all/any and a review
 * SLA, and carry checklist lines; templates can count offsets in working days
 * (weekends and holidays skipped).
 */
class TemplateService
{
    /** @var list<string> reviewers who could not be added (not on this client, or the assignee) */
    public array $notes = [];

    public function __construct(
        private readonly TaskService $tasks,
        private readonly ActivityLogger $activity,
        private readonly ReviewService $reviews,
        private readonly WorkspaceAccess $access,
        private readonly Capacity $capacity,
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
        $this->notes = [];
        if ($template->working_days) {
            $this->capacity->load($startOn->toDateString(), $startOn->addDays(800)->toDateString());
            $startOn = $this->workingDay($startOn);
        }

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
                    'due_on' => ($template->working_days ? $this->addWorkingDays($startOn, $step->offset_days) : $startOn->addDays($step->offset_days))->toDateString(),
                ], $actor);
                $this->review($task, $step, $workspace, $actor);
                $this->checklist($task, $step);

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

    /** E-40: reviewers, rule and SLA from the step (skipping anyone who can't review here). */
    private function review(Task $task, WorkflowTemplateStep $step, Workspace $workspace, User $actor): void
    {
        $ids = array_map('intval', $step->review_user_ids ?? []);
        if ($step->review_leads) {
            $ids = [...$ids, ...$workspace->members()->wherePivotIn('role', [Workspace::ROLE_LEAD, Workspace::ROLE_OWNER])->pluck('users.id')->map(fn ($id) => (int) $id)->all()];
        }
        $valid = [];
        foreach (array_unique($ids) as $id) {
            $user = User::query()->active()->find($id);
            if ($user === null || $id === $task->assignee_id || ! $this->access->canContribute($user, $workspace)) {
                if ($user !== null && $id !== $task->assignee_id) {
                    $this->notes[] = sprintf('%s is not on this client, so was not added as reviewer of "%s".', $user->name, $task->title);
                }

                continue;
            }
            $valid[] = $id;
        }
        if ($valid !== []) {
            $this->reviews->setReviewers($task, $valid, $step->review_mode ?? 'all', $actor);
        }
        if ($step->review_sla_days !== null) {
            $task->forceFill(['review_sla_days' => $step->review_sla_days])->save();
        }
    }

    private function checklist(Task $task, WorkflowTemplateStep $step): void
    {
        $position = (int) $task->checklistItems()->reorder()->max('position');
        foreach (array_slice($step->checklist ?? [], 0, 30) as $line) {
            $optional = str_starts_with(trim($line), '?');
            $label = mb_substr(trim(ltrim(trim($line), '?')), 0, 200);
            if ($label !== '') {
                $task->checklistItems()->create(['label' => $label, 'required' => ! $optional, 'position' => ++$position]);
            }
        }
    }

    private function workingDay(CarbonImmutable $day): CarbonImmutable
    {
        for ($i = 0; $i < 30 && $this->capacity->isDayOff($day->toDateString()); $i++) {
            $day = $day->addDay();
        }

        return $day;
    }

    private function addWorkingDays(CarbonImmutable $from, int $days): CarbonImmutable
    {
        $day = $from;
        for ($added = 0; $added < $days;) {
            $day = $day->addDay();
            if (! $this->capacity->isDayOff($day->toDateString())) {
                $added++;
            }
        }

        return $day;
    }
}
