<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Pivot row for workspace membership.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string $role
 * @property int|null $department_id
 */
class WorkspaceMember extends Pivot
{
    protected $table = 'workspace_user';

    public $incrementing = true;
}
