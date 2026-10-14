<?php

namespace App\Domain\Work;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Models\ReviewRule;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * E-34: when a task goes to In Review with nobody named, fill its reviewers from the most
 * specific matching rule. The submitter can still change them on the task. Round-robin
 * picks the next person in turn (skipping the assignee and anyone who left the client).
 */
class ReviewRules
{
    public function __construct(
        private readonly ReviewService $reviews,
        private readonly WorkspaceAccess $access,
        private readonly ActivityLogger $activity,
    ) {}

    public function match(Task $task): ?ReviewRule
    {
        $deliverable = is_array($task->work_details) ? ($task->work_details['deliverable_type'] ?? null) : null;

        return ReviewRule::query()->where('workspace_id', $task->workspace_id)->get()
            ->filter(fn (ReviewRule $r) => ($r->project_id === null || $r->project_id === $task->project_id)
                && ($r->deliverable_type === null || $r->deliverable_type === $deliverable)
                && ($r->task_type === null || $r->task_type === $task->type))
            ->sortByDesc(fn (ReviewRule $r) => [$r->specificity(), $r->id])
            ->first();
    }

    /**
     * Returns the user ids set as reviewers (empty when no rule applies).
     *
     * @return list<int>
     */
    public function apply(Task $task, User $actor): array
    {
        $rule = $this->match($task);
        $workspace = Workspace::query()->find($task->workspace_id);
        if ($rule === null || $workspace === null) {
            return [];
        }

        $eligible = [];
        foreach ($rule->user_ids as $id) {
            $user = User::query()->active()->find((int) $id);
            if ($user !== null && $user->id !== $task->assignee_id && $this->access->canContribute($user, $workspace)) {
                $eligible[] = $user->id;
            }
        }
        if ($eligible === []) {
            return [];
        }

        $mode = $rule->mode;
        if ($mode === 'round_robin') {
            // Lock the rule so two submissions at once don't pick the same person.
            $picked = DB::transaction(function () use ($rule, $eligible) {
                $fresh = ReviewRule::query()->lockForUpdate()->findOrFail($rule->id);
                $pick = $eligible[$fresh->next_index % count($eligible)];
                $fresh->forceFill(['next_index' => $fresh->next_index + 1])->save();

                return $pick;
            });
            $ids = [$picked];
            $mode = 'any';
        } else {
            $ids = $eligible;
        }

        $this->reviews->setReviewers($task, $ids, $mode, $actor);
        $this->activity->log('task.reviewers-from-rule', $task, ['rule_id' => $rule->id, 'user_ids' => $ids], $actor);

        return $ids;
    }
}
