<?php

namespace App\Models;

use App\Models\Concerns\IsLinkable;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A client company. Everything the agency does for a client lives in its workspace.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $industry
 * @property string|null $website
 * @property string $status
 * @property string $color
 * @property string|null $description
 * @property string|null $primary_contact_name
 * @property int|null $created_by
 * @property-read int|null $members_count
 * @property-read int|null $open_projects_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'industry', 'website', 'status', 'color', 'description', 'primary_contact_name', 'created_by'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, IsLinkable, SoftDeletes;

    public const STATUSES = ['active', 'paused', 'archived'];

    public const ROLE_OWNER = 'owner';

    public const ROLE_LEAD = 'lead';

    public const ROLE_MEMBER = 'member';

    public const ROLE_GUEST = 'guest';

    /** Workspace roles, strongest first. */
    public const ROLES = [self::ROLE_OWNER, self::ROLE_LEAD, self::ROLE_MEMBER, self::ROLE_GUEST];

    public const COLORS = ['slate', 'blue', 'violet', 'emerald', 'amber', 'rose', 'cyan', 'orange'];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return BelongsToMany<User, $this, WorkspaceMember, 'membership'>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_user')
            ->using(WorkspaceMember::class)
            ->as('membership')
            ->withPivot(['id', 'role', 'department_id'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * Billing setup (P6), if Finance configured one.
     *
     * @return HasOne<BillingProfile, $this>
     */
    public function billingProfile(): HasOne
    {
        return $this->hasOne(BillingProfile::class);
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * @return HasMany<Label, $this>
     */
    public function labels(): HasMany
    {
        return $this->hasMany(Label::class);
    }

    /**
     * Workspaces the user may see: all for workspaces.view-all, otherwise memberships.
     *
     * @param  Builder<Workspace>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->can('workspaces.view-all')) {
            return;
        }

        $query->whereHas('members', fn (Builder $q) => $q->whereKey($user->getKey()));
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function linkLabel(): string
    {
        return $this->name;
    }
}
