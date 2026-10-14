<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * E-24: a GitHub repository linked to a client. PRs and branches naming a task key
 * (BRW-24) link to that task; status moves are optional per repository.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $full_name owner/repo (lower-case)
 * @property string|null $on_open_status task status slug when a PR opens (e.g. in-review)
 * @property string|null $on_merge_status task status slug when it merges (e.g. done)
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'full_name', 'on_open_status', 'on_merge_status', 'created_by'])]
class CodeRepository extends Model
{
    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
