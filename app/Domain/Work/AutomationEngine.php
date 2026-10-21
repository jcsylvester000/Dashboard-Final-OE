<?php

namespace App\Domain\Work;

use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\AutomationRule;
use App\Models\AutomationRun;
use App\Models\Label;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * E-37: a client's own rules. When (status changes / label added / file uploaded / due
 * soon) -> If (department, type, project) -> Then (assign, set status, add label,
 * notify, create subtask). Each event fires a matching rule once; rules that set off
 * other rules stop at depth 3 (logged as a loop). Every run is logged with its outcome.
 * Actions go through TaskService as the rule's author, so every guard still applies.
 */
class AutomationEngine
{
    public const MAX_DEPTH = 3;

    private static int $depth = 0;

    public function __construct(
        private readonly TaskService $tasks,
        private readonly Notifier $notifier,
        private readonly WorkspaceAccess $access,
    ) {}

    public function handle(string $trigger, Task $task, ?string $value = null): void
    {
        $rules = AutomationRule::query()->where('workspace_id', $task->workspace_id)->where('trigger', $trigger)->where('enabled', true)->orderBy('id')->get();
        if ($rules->isEmpty()) {
            return;
        }
        // Work on a fresh copy: a blocked action must not leave the caller's model half-changed.
        $task = Task::query()->find($task->id);
        if ($task === null) {
            return;
        }
        foreach ($rules as $rule) {
            if ($rule->trigger_value !== null && $rule->trigger_value !== '' && $rule->trigger_value !== $value) {
                continue;
            }
            if (! $this->matches($rule, $task)) {
                continue;
            }
            if (self::$depth >= self::MAX_DEPTH) {
                $this->log($rule, $task, 'loop', 'Stopped: rules set each other off more than '.self::MAX_DEPTH.' times.');

                continue;
            }
            self::$depth++;
            try {
                $notes = $this->run($rule, $task);
                $this->log($rule, $task, 'done', implode(' ', $notes) ?: null);
            } catch (ValidationException $e) {
                $first = collect($e->errors())->flatten()->first();
                $this->log($rule, $task, 'failed', is_string($first) ? $first : 'Blocked.');
            } catch (Throwable $e) {
                report($e);
                $this->log($rule, $task, 'failed', 'Something went wrong running this rule.');
            } finally {
                self::$depth--;
            }
        }
    }

    private function matches(AutomationRule $rule, Task $task): bool
    {
        $c = $rule->conditions;

        return (empty($c['department_id']) || (int) $c['department_id'] === $task->department_id)
            && (empty($c['type']) || $c['type'] === $task->type)
            && (empty($c['project_id']) || (int) $c['project_id'] === $task->project_id);
    }

    /**
     * @return list<string>
     */
    private function run(AutomationRule $rule, Task $task): array
    {
        $workspace = Workspace::query()->findOrFail($task->workspace_id);
        $actor = $rule->created_by !== null ? User::query()->active()->find($rule->created_by) : null;
        if ($actor === null) {
            throw ValidationException::withMessages(['rule' => 'The person who made this rule is no longer active; re-save it.']);
        }
        $notes = [];
        foreach ($rule->actions as $action) {
            $value = $action['value'] ?? null;
            switch ($action['type'] ?? '') {
                case 'assign':
                    $user = User::query()->active()->find((int) $value);
                    if ($user === null || ! $this->access->canContribute($user, $workspace)) {
                        $notes[] = 'Assignee is not on this client.';
                        break;
                    }
                    $this->tasks->update($task, ['assignee_id' => $user->id], $actor);
                    $notes[] = 'Assigned to '.$user->name.'.';
                    break;
                case 'set_status':
                    $status = TaskStatus::ordered()->firstWhere('slug', (string) $value);
                    if ($status !== null && $status->id !== $task->status_id) {
                        $this->tasks->update($task, ['status_id' => $status->id], $actor);
                        $notes[] = 'Status set to '.$status->name.'.';
                    }
                    break;
                case 'add_label':
                    $label = Label::query()->whereKey((int) $value)->where('workspace_id', $workspace->id)->first();
                    if ($label !== null && ! $task->labels()->whereKey($label->id)->exists()) {
                        $task->labels()->attach($label->id);
                        $notes[] = 'Label '.$label->name.' added.';
                        $this->handle('label_added', $task, (string) $label->id);
                    }
                    break;
                case 'notify':
                    $ids = $this->recipients((string) $value, $task, $workspace);
                    $this->notifier->send($ids, NotificationKind::Status, $workspace, [
                        'title' => sprintf('Rule "%s"', $rule->name),
                        'body' => $task->title,
                        'url' => $this->notifier->taskUrl($task, $workspace),
                        'task_id' => $task->id,
                    ]);
                    $notes[] = 'Notified '.count($ids).' person(s).';
                    break;
                case 'create_subtask':
                    $title = trim((string) $value);
                    if ($title !== '') {
                        $this->tasks->create($workspace, ['title' => mb_substr($title, 0, 255), 'parent_id' => $task->id, 'project_id' => $task->project_id, 'department_id' => $task->department_id], $actor);
                        $notes[] = 'Subtask "'.$title.'" created.';
                    }
                    break;
            }
        }

        return $notes;
    }

    /**
     * @return list<int>
     */
    private function recipients(string $who, Task $task, Workspace $workspace): array
    {
        return match ($who) {
            'assignee' => $task->assignee_id !== null ? [$task->assignee_id] : [],
            'leads' => $workspace->members()->wherePivotIn('role', [Workspace::ROLE_LEAD, Workspace::ROLE_OWNER])->pluck('users.id')->map(fn ($id) => (int) $id)->all(),
            'dept_leads' => $task->department_id === null ? [] : User::query()->active()->role('dept-lead')->where('primary_department_id', $task->department_id)->pluck('id')->map(fn ($id) => (int) $id)->all(),
            default => ctype_digit($who) ? [(int) $who] : [],
        };
    }

    private function log(AutomationRule $rule, Task $task, string $outcome, ?string $detail): void
    {
        AutomationRun::query()->create(['automation_rule_id' => $rule->id, 'task_id' => $task->id, 'outcome' => $outcome, 'detail' => $detail !== null ? mb_substr($detail, 0, 500) : null, 'created_at' => now()]);
        if ($outcome === 'done') {
            $rule->increment('runs');
        }
    }
}
