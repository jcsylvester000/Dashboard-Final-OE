<?php

namespace App\Events\Work;

use App\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Open task past its due date (once per due date). $escalate is true when it is
 * more than two days late; the department lead (or workspace leads) are told.
 */
class TaskOverdue
{
    use Dispatchable;

    public function __construct(public readonly Task $task, public readonly bool $escalate = false) {}
}
