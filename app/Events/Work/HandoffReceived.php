<?php

namespace App\Events\Work;

use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** "Send to [Department]" created $next from $from. */
class HandoffReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Task $from, public readonly Task $next, public readonly User $actor) {}
}
