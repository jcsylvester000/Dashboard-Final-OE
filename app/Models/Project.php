<?php

namespace App\Models;

use App\Models\Concerns\HasLabels;
use App\Models\Concerns\HasMentions;
use App\Models\Concerns\IsLinkable;
use App\Models\Contracts\Mentionable;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A unit of client work inside a workspace: project, campaign, SEO engagement,
 * research study or product release.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $name
 * @property string $type
 * @property string $status
 * @property int|null $lead_user_id
 * @property string|null $description
 * @property Carbon|null $start_on
 * @property Carbon|null $due_on
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'name', 'type', 'status', 'lead_user_id', 'description', 'start_on', 'due_on', 'created_by'])]
class Project extends Model implements Mentionable
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory, HasLabels, HasMentions, IsLinkable, SoftDeletes;

    /** type => label */
    public const TYPES = [
        'project' => 'Project',
        'campaign' => 'Campaign',
        'seo' => 'SEO engagement',
        'research' => 'Research study',
        'release' => 'Product release',
    ];

    /** status => label */
    public const STATUSES = [
        'planning' => 'Planning',
        'active' => 'Active',
        'on_hold' => 'On hold',
        'completed' => 'Completed',
        'archived' => 'Archived',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_on' => 'date',
            'due_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_user_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function mentionWorkspaceId(): int
    {
        return $this->workspace_id;
    }

    public function linkLabel(): string
    {
        return $this->name;
    }
}
