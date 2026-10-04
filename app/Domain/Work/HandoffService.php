<?php

namespace App\Domain\Work;

use App\Domain\Identity\ActivityLogger;
use App\Models\Department;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Send to [Department]": creates the next department's task, linked to the
 * source and waiting on it, so work flows Research > Product > Dev > SEO > Marketing
 * without anyone losing the thread.
 */
class HandoffService
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly ActivityLogger $activity,
    ) {}

    /**
     * @param  array{department_id: int, assignee_id?: int|null, title?: string|null, note?: string|null, due_on?: string|null}  $data
     */
    public function handoff(Task $from, array $data, User $actor): Task
    {
        return DB::transaction(function () use ($from, $data, $actor) {
            $department = Department::findOrFail($data['department_id']);
            $from->loadMissing('workspace');

            $from->loadMissing('status');
            $sourceDone = $from->status->isDone();

            $next = $this->tasks->create($from->workspace, [
                'project_id' => $from->project_id,
                'department_id' => $department->id,
                // Waits in Backlog until the source is done, then moves to To Do automatically.
                'status_id' => TaskStatus::idFor($sourceDone ? 'todo' : 'backlog'),
                'title' => $data['title'] ?? ($department->name.': '.$from->title),
                'description' => $data['note'] ?? null,
                'assignee_id' => $data['assignee_id'] ?? null,
                'due_on' => $data['due_on'] ?? null,
                'priority' => $from->priority,
            ], $actor);

            $next->forceFill(['handoff_from_task_id' => $from->id])->save();

            // The new task waits on the source until the source is done.
            $next->dependencies()->syncWithoutDetaching([
                $from->id => ['type' => 'handoff', 'created_by' => $actor->id],
            ]);

            // Keep the receiving department lead in the loop if they work on this client.
            $leadId = $department->lead_user_id;
            if ($leadId !== null && $from->workspace->members()->whereKey($leadId)->exists()) {
                $this->tasks->watch($next, [$leadId]);
            }
            $this->tasks->watch($from, [$actor->id]);

            $this->activity->log('task.handoff-sent', $from, ['to_task_id' => $next->id, 'department' => $department->name], $actor);
            $this->activity->log('task.handoff-received', $next, ['from_task_id' => $from->id], $actor);

            return $next;
        });
    }
}
