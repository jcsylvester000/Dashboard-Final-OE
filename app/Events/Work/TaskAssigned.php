<?php

namespace App\Events\Work;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A task was given to someone (on create or reassignment). */
class TaskAssigned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Task $task, public readonly ?User $actor) {}
}
