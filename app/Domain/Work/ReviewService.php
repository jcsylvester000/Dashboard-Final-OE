<?php

namespace App\Domain\Work;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\Task;
use App\Models\TaskReviewer;
use App\Models\User;
use App\Models\Workspace;
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
