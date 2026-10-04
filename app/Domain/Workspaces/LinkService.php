<?php

namespace App\Domain\Workspaces;

use App\Models\Project;
use App\Models\RecordLink;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Gate;

/**
 * Cross-references ("relates to") between records, including across workspaces.
 * A user only ever sees links whose other end they are allowed to view.
 */
class LinkService
{
    /** Morph aliases that can be linked. Tasks join in P3. */
    public const TYPES = ['workspace', 'project'];

    public function resolve(string $type, int $id): ?Model
    {
        return match ($type) {
            'workspace' => Workspace::find($id),
            'project' => Project::with('workspace')->find($id),
            default => null,
        };
    }

    /**
     * The workspace whose contributors may add/remove links on this record.
     */
    public function owningWorkspace(Model $record): ?Workspace
    {
        return match (true) {
            $record instanceof Workspace => $record,
            $record instanceof Project => $record->workspace,
            default => null,
        };
    }

    /**
     * @return array<int, array{id: int, direction: string, type: string, record_id: int, label: string, context: string|null, url: string}>
     */
    public function linksFor(Model $record, User $viewer): array
    {
        $type = $record->getMorphClass();
        $id = $record->getKey();

        $withWorkspace = fn (MorphTo $m) => $m->morphWith([Project::class => ['workspace']]);

        $outgoing = RecordLink::with(['target' => $withWorkspace])
            ->where('source_type', $type)->where('source_id', $id)
            ->latest('id')->get()
            ->map(fn (RecordLink $l) => [$l, 'outgoing', $l->target]);

        $incoming = RecordLink::with(['source' => $withWorkspace])
            ->where('target_type', $type)->where('target_id', $id)
            ->latest('id')->get()
            ->map(fn (RecordLink $l) => [$l, 'incoming', $l->source]);

        $rows = [];
        foreach ($outgoing->concat($incoming) as [$link, $direction, $other]) {
            if ($other === null || Gate::forUser($viewer)->denies('view', $other)) {
                continue;
            }
            $rows[] = ['id' => $link->id, 'direction' => $direction, ...$this->describe($other)];
        }

        return $rows;
    }

    /**
     * Records the user can see, for the reference picker.
     *
     * @return list<array{type: string, record_id: int, label: string, context: string|null, url: string}>
     */
    public function search(User $user, string $term, int $limit = 10): array
    {
        $like = '%'.mb_strtolower(trim($term)).'%';

        $workspaces = Workspace::query()->visibleTo($user)
            ->whereRaw('lower(name) like ?', [$like])
            ->orderBy('name')->limit($limit)->get();

        $projects = Project::query()
            ->with('workspace')
            ->whereHas('workspace', fn (Builder $q) => $q->visibleTo($user))
            ->whereRaw('lower(name) like ?', [$like])
            ->orderBy('name')->limit($limit)->get();

        return $workspaces->concat($projects)
            ->map(fn (Model $m) => $this->describe($m))
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @return array{type: string, record_id: int, label: string, context: string|null, url: string}
     */
    public function describe(Model $record): array
    {
        return match (true) {
            $record instanceof Workspace => [
                'type' => 'workspace',
                'record_id' => $record->id,
                'label' => $record->name,
                'context' => 'Workspace',
                'url' => route('workspaces.show', $record),
            ],
            $record instanceof Project => [
                'type' => 'project',
                'record_id' => $record->id,
                'label' => $record->name,
                'context' => $record->workspace->name.' · '.(Project::TYPES[$record->type] ?? $record->type),
                'url' => route('workspaces.projects.show', [$record->workspace, $record]),
            ],
            default => [
                'type' => $record->getMorphClass(),
                'record_id' => (int) $record->getKey(),
                'label' => '#'.$record->getKey(),
                'context' => null,
                'url' => '#',
            ],
        };
    }
}
