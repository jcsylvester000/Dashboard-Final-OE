<?php

namespace App\Events\Work;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A task changed status. $unblockedBy is set when the task moved to To Do
 * automatically because the task it waited on was finished.
 */
class TaskStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Task $task,
        public readonly int $fromStatusId,
        public readonly ?User $actor,
        public readonly ?Task $unblockedBy = null,
    ) {}
}
