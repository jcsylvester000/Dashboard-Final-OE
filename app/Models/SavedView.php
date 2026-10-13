<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved task list / board view: filters and grouping, personal or shared with the client team.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $user_id
 * @property string $name
 * @property string $page list | board
 * @property array<string, string|int|bool> $filters
 * @property bool $shared
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['workspace_id', 'user_id', 'name', 'page', 'filters', 'shared'])]
class SavedView extends Model
{
    public const PAGES = ['list', 'board'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['filters' => 'array', 'shared' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Views a person sees on a client: their own plus the shared ones.
     *
     * @param  Builder<SavedView>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q->where('user_id', $user->id)->orWhere('shared', true));
    }
}
