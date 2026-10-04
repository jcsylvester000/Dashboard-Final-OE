<?php

namespace App\Events\Work;

use App\Models\Contracts\Mentionable;
use App\Models\User;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/** Someone was newly @mentioned on a task, comment or project. */
class UserMentioned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  Model&Mentionable  $record
     */
    public function __construct(public readonly Model $record, public readonly int $userId, public readonly ?User $actor) {}
}
