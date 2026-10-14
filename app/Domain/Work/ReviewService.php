<?php

namespace App\Domain\Work;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\Attachment;
use App\Models\Task;
use App\Models\TaskReviewer;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * E-07: named reviewers per task. Rule "all" needs every reviewer's approval for the
 * current round, "any" needs one. A new round starts each time the task enters In Review.
 * Leads can still decide without being named (override), as before.
 */
class ReviewService
{
    public function __construct(
        private readonly ActivityLogger $activity,
        private readonly WorkspaceAccess $access,
    ) {}

    /**
     * @param  list<int>  $userIds
     */
    public function setReviewers(Task $task, array $userIds, string $rule, User $actor): void
    {
        $workspace = Workspace::query()->findOrFail($task->workspace_id);
        $ids = [];
        foreach (array_unique(array_map('intval', $userIds)) as $id) {
            $user = User::query()->active()->find($id);
            if ($user === null || ! $this->access->canContribute($user, $workspace)) {
                throw ValidationException::withMessages(['user_ids' => __('Reviewers must be active members of this client.')]);
            }
            if ($id === $task->assignee_id) {
                throw ValidationException::withMessages(['user_ids' => __('The assignee can\'t review their own work.')]);
            }
            $ids[] = $id;
        }

        $task->reviewers()->whereNotIn('user_id', $ids)->delete();
        $existing = $task->reviewers()->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        foreach (array_diff($ids, $existing) as $id) {
            $task->reviewers()->create(['user_id' => $id]);
        }
        $task->forceFill(['review_rule' => $rule === 'any' ? 'any' : 'all'])->save();
        $this->activity->log('task.reviewers-set', $task, ['user_ids' => $ids, 'rule' => $task->review_rule], $actor);
    }

    /**
     * Approve or send back a task that is In Review (E-07). With rule "all", one approval
     * out of several keeps it In Review. $via = "inbox" for approvals made from the alert (E-38).
     *
     * @return string the message for the person deciding
     */
    public function decide(Task $task, User $user, string $decision, string $note, string $via = 'task'): string
    {
        $tasks = app(TaskService::class);
        $comments = app(CommentService::class);
        $named = $this->isReviewer($task, $user);
        if ($named) {
            $this->record($task, $user, $decision === 'approve' ? 'approved' : 'changes', $note !== '' ? $note : null);
        }
        $where = $via === 'inbox' ? ['via' => 'inbox'] : [];

        if ($decision === 'approve' && $named && ! $this->satisfied($task)) {
            $progress = $this->progress($task);
            $this->activity->log('task.approved', $task, ['note' => $note !== '' ? $note : null, 'partial' => true, ...$where], $user);

            return __('Approved (:a of :t). Waiting for :names.', ['a' => $progress['approved'], 't' => $progress['total'], 'names' => implode(', ', $progress['waiting'])]);
        }

        if ($decision === 'approve') {
            $tasks->update($task, ['status_id' => TaskStatus::idFor('done')], $user);
            $this->activity->log('task.approved', $task, ['note' => $note !== '' ? $note : null, ...$where], $user);
            if ($note !== '') {
                $comments->create($task, __('Approved: :note', ['note' => $note]), $user);
            } elseif ($via === 'inbox') {
                $comments->create($task, __('Approved (from the inbox).'), $user);
            }

            return __('Approved and marked Done.');
        }

        $tasks->update($task, ['status_id' => TaskStatus::idFor('in-progress')], $user);
        $this->activity->log('task.changes-requested', $task, $where, $user);
        $assignee = $task->assignee;
        $tag = $assignee !== null && $assignee->id !== $user->id ? '@['.$assignee->name.'](user:'.$assignee->id.') ' : '';
        $comments->create($task, $tag.__('Changes requested: :note', ['note' => $note]), $user);

        return __('Sent back with your notes.');
    }

    /**
     * E-38: a named reviewer may approve straight from the alert when the task is still In
     * Review, they haven't decided yet, and they opened it in the last 24 hours after its
     * newest file version (so nobody approves work they haven't seen).
     */
    public function canQuickApprove(Task $task, User $user): bool
    {
        if ($task->status_id !== TaskStatus::idFor('in-review')) {
            return false;
        }
        $row = $task->reviewers()->where('user_id', $user->id)->first();
        if ($row === null || $row->decision === 'approved' || $row->seen_at === null || $row->seen_at->lt(now()->subDay())) {
            return false;
        }
        $newestFile = Attachment::query()->where('attachable_type', $task->getMorphClass())->where('attachable_id', $task->id)->max('created_at');

        return $newestFile === null || $row->seen_at->gte(CarbonImmutable::parse((string) $newestFile));
    }

    /** E-38: remember when a named reviewer last opened the task. */
    public function markSeen(Task $task, User $user): void
    {
        $task->reviewers()->where('user_id', $user->id)->update(['seen_at' => now()]);
    }

    public function isReviewer(Task $task, User $user): bool
    {
        return $task->reviewers()->where('user_id', $user->id)->exists();
    }

    public function record(Task $task, User $user, string $decision, ?string $note): void
    {
        $task->reviewers()->where('user_id', $user->id)->update(['decision' => $decision, 'note' => $note, 'decided_at' => now()]);
    }

    /** A new review round: everyone decides again. */
    public function resetRound(Task $task): void
    {
        $task->reviewers()->update(['decision' => null, 'note' => null, 'decided_at' => null]);
    }

    /** True when there are no named reviewers or the rule is met. */
    public function satisfied(Task $task): bool
    {
        $rows = $task->reviewers()->get(['decision']);
        if ($rows->isEmpty()) {
            return true;
        }
        $approved = $rows->where('decision', 'approved')->count();

        return $task->review_rule === 'any' ? $approved > 0 : $approved === $rows->count();
    }

    /**
     * People who still have to decide this round.
     *
     * @return list<int>
     */
    public function pendingUserIds(Task $task): array
    {
        return $task->reviewers()->where(fn ($q) => $q->whereNull('decision')->orWhere('decision', '!=', 'approved'))
            ->pluck('user_id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /**
     * @return array{approved: int, total: int, waiting: list<string>}
     */
    public function progress(Task $task): array
    {
        $rows = $task->reviewers()->with('user:id,name')->get();

        return [
            'approved' => $rows->where('decision', 'approved')->count(),
            'total' => $rows->count(),
            'waiting' => $rows->where('decision', '!=', 'approved')->map(fn (TaskReviewer $r) => (string) ($r->user->name ?? 'Former member'))->values()->all(),
        ];
    }
}
