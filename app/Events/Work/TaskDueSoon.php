<?php

namespace App\Events\Work;

use App\Models\Task;
use Illuminate\Foundation\Events\Dispatchable;

/** Open task due today or tomorrow (sent once per due date by work:alerts). */
class TaskDueSoon
{
    use Dispatchable;

    public function __construct(public readonly Task $task) {}
}
