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
use Illuminate\Support\Facades\DB;

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
 * @property string|null $key short task-key prefix, e.g. BRW (BRW-24)
 * @property int $next_task_number
 * @property list<array{key: string, label: string, type: string, options?: list<string>}>|null $custom_fields B-07
 * @property array<int|string, string>|null $status_labels B-07: status id => this client's name for it
 * @property int|null $created_by
 * @property-read int|null $members_count
 * @property-read int|null $open_projects_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'slug', 'industry', 'website', 'status', 'color', 'description', 'primary_contact_name', 'created_by', 'key'])]
class Workspace extends Model
{
    /** @use HasFactory<WorkspaceFactory> */
    use HasFactory, IsLinkable, SoftDeletes;

    public const STATUSES = ['active', 'paused', 'archived'];

    public const ROLE_OWNER = 'owner';

    public const ROLE_LEAD = 'lead';

    public const ROLE_MEMBER = 'member';

    public const ROLE_GUEST = 'guest';

    /** B-07: custom task fields a client can have (keeps the list small and typed). */
    public const MAX_CUSTOM_FIELDS = 12;

    /** @var array<string, string> */
    public const CUSTOM_FIELD_TYPES = ['text' => 'Text', 'number' => 'Number', 'date' => 'Date', 'select' => 'Pick one', 'checkbox' => 'Yes / no', 'url' => 'Link'];

    /** Workspace roles, strongest first. */
    public const ROLES = [self::ROLE_OWNER, self::ROLE_LEAD, self::ROLE_MEMBER, self::ROLE_GUEST];

    public const COLORS = ['slate', 'blue', 'violet', 'emerald', 'amber', 'rose', 'cyan', 'orange'];

    protected static function booted(): void
    {
        static::creating(function (Workspace $workspace) {
            $workspace->key ??= self::suggestKey($workspace->name, self::query()->withTrashed()->whereNotNull('key')->pluck('key')->map(fn ($k) => (string) $k)->all());
        });
    }

    /**
     * A 2-4 letter key from the client name: initials ("Desert Sun Realty" -> DSR),
     * or the first letters of a one-word name ("Brewhouse" -> BRE). A digit is
     * added when the key is already used.
     *
     * @param  array<int, string>  $taken
     */
    public static function suggestKey(string $name, array $taken = []): string
    {
        $words = array_values(array_filter(preg_split('/[^A-Za-z0-9]+/', $name) ?: [], fn ($w) => $w !== ''));
        $base = count($words) >= 2
            ? implode('', array_map(fn ($w) => $w[0], array_slice($words, 0, 4)))
            : substr($words[0] ?? 'WS', 0, 3);
        $base = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $base) ?? '');
        if ($base === '' || ! ctype_alpha($base[0])) {
            $base = 'W'.$base;
        }
        $base = substr(str_pad($base, 2, 'X'), 0, 4);

        $key = $base;
        for ($i = 2; in_array($key, $taken, true); $i++) {
            $key = substr($base, 0, 5 - strlen((string) $i)).$i;
        }

        return $key;
    }

    /**
     * Next task number for a client, safe under concurrent creates (row lock).
     */
    public static function claimTaskNumber(int $workspaceId): int
    {
        return DB::transaction(function () use ($workspaceId) {
            $n = (int) DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->value('next_task_number') + 1;
            DB::table('workspaces')->where('id', $workspaceId)->update(['next_task_number' => $n]);

            return $n;
        });
    }

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
     * @return HasMany<SavedView, $this>
     */
    public function views(): HasMany
    {
        return $this->hasMany(SavedView::class);
    }

    /**
     * @return HasMany<Sprint, $this>
     */
    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class);
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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'custom_fields' => 'array',
            'status_labels' => 'array',
        ];
    }

    /** This client's name for a status (falls back to the shared name). */
    public function statusLabel(int $statusId, string $default): string
    {
        $label = $this->status_labels[$statusId] ?? '';

        return $label !== '' ? $label : $default;
    }
}
