<?php

namespace App\Domain\Reports;

use App\Models\ActivityLog;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * The workspace Overview dashboard: work by department and status, blocked
 * items, what's due soon, and recent activity on its tasks and projects.
 */
class WorkspaceOverview
{
    /**
     * @return array<string, mixed>
     */
    public function build(Workspace $workspace): array
    {
        return [
            'departments' => $this->byDepartment($workspace),
            'blocked' => $this->blocked($workspace),
            'dueSoon' => $this->dueSoon($workspace),
            'activity' => $this->activity($workspace),
        ];
    }

    /**
     * Rows per department: counts per status category, overdue and done in the last 30 days.
     *
     * @return list<array<string, mixed>>
     */
    private function byDepartment(Workspace $workspace): array
    {
        $category = TaskStatus::ordered()->pluck('category', 'id');
        $today = now()->toDateString();

        $tasks = DB::table('tasks')
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('completed_at')->orWhere('completed_at', '>=', now()->subDays(30)))
            ->get(['department_id', 'status_id', 'due_on']);

        $rows = [];
        foreach ($tasks as $t) {
            $id = (int) $t->department_id;
            $rows[$id] ??= ['open' => 0, 'active' => 0, 'blocked' => 0, 'done' => 0, 'overdue' => 0];
            $cat = (string) ($category[$t->status_id] ?? 'open');
            $rows[$id][$cat]++;
            if ($cat !== TaskStatus::CATEGORY_DONE && $t->due_on !== null && substr((string) $t->due_on, 0, 10) < $today) {
                $rows[$id]['overdue']++;
            }
        }

        $departments = DB::table('departments')->orderBy('position')->get(['id', 'name', 'color']);
        $out = [];
        foreach ($departments as $d) {
            if (isset($rows[(int) $d->id])) {
                $out[] = ['id' => (int) $d->id, 'name' => (string) $d->name, 'color' => (string) $d->color, ...$rows[(int) $d->id]];
            }
        }
        if (isset($rows[0])) {
            $out[] = ['id' => null, 'name' => 'No department', 'color' => 'slate', ...$rows[0]];
        }

        return $out;
    }

    /**
     * Tasks in Blocked status, or waiting on unfinished tasks (excluding Backlog handoffs that are simply queued).
     *
     * @return list<array<string, mixed>>
     */
    private function blocked(Workspace $workspace): array
    {
        $blockedIds = TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_BLOCKED)->pluck('id')->all();
        $doneIds = TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id')->all();
        $backlog = TaskStatus::idFor('backlog');

        $waiting = DB::table('task_dependencies as d')
            ->join('tasks as dep', 'dep.id', '=', 'd.depends_on_task_id')
            ->whereNull('dep.deleted_at')
            ->whereNotIn('dep.status_id', $doneIds)
            ->select('d.task_id');

        return Task::query()
            ->with(['assignee:id,name', 'department:id,name'])
            ->where('workspace_id', $workspace->id)
            ->whereNotIn('status_id', $doneIds)
            ->where(fn ($q) => $q->whereIn('status_id', $blockedIds)
                ->orWhere(fn ($q2) => $q2->where('status_id', '!=', $backlog)->whereIn('id', $waiting)))
            ->orderByRaw('due_on is null, due_on asc')
            ->limit(10)
            ->get()
            ->map(fn (Task $t): array => [
                'id' => $t->id,
                'title' => $t->title,
                'assignee' => $t->assignee?->name,
                'department' => $t->department?->name,
                'reason' => in_array($t->status_id, $blockedIds) ? 'Blocked' : 'Waiting on another task',
                'due_on' => $t->due_on?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Open tasks due in the next 14 days (and anything overdue).
     *
     * @return list<array<string, mixed>>
     */
    private function dueSoon(Workspace $workspace): array
    {
        $doneIds = TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id')->all();

        return Task::query()
            ->with(['assignee:id,name', 'department:id,name'])
            ->where('workspace_id', $workspace->id)
            ->whereNotIn('status_id', $doneIds)
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<=', now()->addDays(14)->toDateString())
            ->orderBy('due_on')
            ->limit(10)
            ->get()
            ->map(fn (Task $t): array => [
                'id' => $t->id,
                'title' => $t->title,
                'assignee' => $t->assignee?->name,
                'department' => $t->department?->name,
                'priority' => $t->priority,
                'due_on' => $t->due_on?->toDateString(),
            ])
            ->values()
            ->all();
    }

    /**
     * Latest timeline entries for this workspace's tasks and projects.
     *
     * @return list<array<string, mixed>>
     */
    private function activity(Workspace $workspace): array
    {
        $taskIds = DB::table('tasks')->where('workspace_id', $workspace->id)->select('id');
        $projectIds = DB::table('projects')->where('workspace_id', $workspace->id)->select('id');

        return ActivityLog::query()
            ->with('actor:id,name')
            ->where(fn ($q) => $q
                ->where(fn ($t) => $t->where('subject_type', 'task')->whereIn('subject_id', $taskIds))
                ->orWhere(fn ($p) => $p->where('subject_type', 'project')->whereIn('subject_id', $projectIds)))
            ->latest('id')
            ->limit(12)
            ->get()
            ->map(fn (ActivityLog $log): array => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor?->name,
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'at' => $log->created_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
