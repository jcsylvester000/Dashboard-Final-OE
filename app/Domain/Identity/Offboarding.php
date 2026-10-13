<?php

namespace App\Domain\Identity;

use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Offboarding (brutal scenario H3): hand a leaver's open work to someone else.
 * Time entries, approvals, comments and files stay attributed to the leaver,
 * so billing history and invoices are unchanged.
 */
class Offboarding
{
    public function __construct(private readonly ActivityLogger $activity) {}

    /**
     * What would move if this person left now (shown before deactivating).
     *
     * @return array{open_tasks: int, projects_led: int, departments_led: int, running_timers: int}
     */
    public function summary(User $user): array
    {
        return [
            'open_tasks' => $this->openTasks($user)->count(),
            'projects_led' => Project::query()->where('lead_user_id', $user->id)->count(),
            'departments_led' => Department::query()->where('lead_user_id', $user->id)->count(),
            'running_timers' => TimeEntry::query()->where('user_id', $user->id)->running()->count(),
        ];
    }

    /**
     * Stops running timers and moves open tasks and lead roles to $to.
     * Tasks in workspaces $to is not a member of become unassigned (they show
     * in the department queue) rather than leaking access to another client.
     *
     * @return array{tasks_reassigned: int, tasks_unassigned: int, projects: int, departments: int, timers_stopped: int}
     */
    public function handOver(User $user, ?User $to, User $by): array
    {
        $result = DB::transaction(function () use ($user, $to) {
            $timers = 0;
            foreach (TimeEntry::query()->where('user_id', $user->id)->running()->get() as $entry) {
                $minutes = $entry->started_at === null ? 1 : (int) max(1, ceil($entry->started_at->diffInSeconds(now()) / 60));
                $entry->forceFill(['ended_at' => now(), 'minutes' => min($minutes, TimeEntry::MAX_MINUTES)])->save();
                $timers++;
            }

            $memberOf = $to === null ? [] : DB::table('workspace_user')->where('user_id', $to->id)->pluck('workspace_id')->all();

            $reassigned = $to === null ? 0 : $this->openTasks($user)->whereIn('workspace_id', $memberOf)->update(['assignee_id' => $to->id]);
            $unassigned = $this->openTasks($user)->update(['assignee_id' => null]);

            $projects = Project::query()->where('lead_user_id', $user->id)
                ->when($to !== null, fn ($q) => $q->whereIn('workspace_id', $memberOf))
                ->update(['lead_user_id' => $to?->id]);
            Project::query()->where('lead_user_id', $user->id)->update(['lead_user_id' => null]);

            $departments = Department::query()->where('lead_user_id', $user->id)->update(['lead_user_id' => $to?->id]);

            return [
                'tasks_reassigned' => $reassigned,
                'tasks_unassigned' => $unassigned,
                'projects' => $projects,
                'departments' => $departments,
                'timers_stopped' => $timers,
            ];
        });

        $this->activity->log('user.offboarded', $user, [...$result, 'to_user_id' => $to?->id], actor: $by);

        return $result;
    }

    /**
     * @return Builder<Task>
     */
    private function openTasks(User $user): Builder
    {
        $done = TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id')->all();

        return Task::query()->where('assignee_id', $user->id)->whereNotIn('status_id', $done);
    }
}
