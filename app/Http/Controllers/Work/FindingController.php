<?php

namespace App\Http\Controllers\Work;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Workspaces\WorkspaceAccess;
use App\Http\Controllers\Controller;
use App\Models\Annotation;
use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Finding;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * E-31: the findings library. Save a comment, a PDF highlight or a pin as a finding
 * (tags, confidence), or write one by hand; search across every client you can see;
 * link findings to the tasks and decisions they inform. Access follows the client.
 */
class FindingController extends Controller
{
    public function __construct(
        private readonly WorkspaceAccess $access,
        private readonly ActivityLogger $activity,
    ) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'tag' => ['nullable', 'string', 'max:30'],
            'confidence' => ['nullable', Rule::in(array_keys(Finding::CONFIDENCE))],
            'workspace' => ['nullable', 'integer'],
        ]);
        $visible = Workspace::query()->visibleTo($user)->orderBy('name')->get(['id', 'name']);
        $ids = $visible->pluck('id')->all();

        $query = Finding::query()->whereIn('workspace_id', $ids)
            ->when($filters['workspace'] ?? null, fn (Builder $q, $w) => $q->where('workspace_id', (int) $w))
            ->when($filters['confidence'] ?? null, fn (Builder $q, $c) => $q->where('confidence', $c))
            ->when($filters['tag'] ?? null, fn (Builder $q, $t) => $q->whereJsonContains('tags', self::tag((string) $t)))
            ->when($filters['q'] ?? null, function (Builder $q, $term) {
                $like = '%'.mb_strtolower(trim((string) $term)).'%';
                $q->where(fn (Builder $w) => $w->whereRaw('lower(title) like ?', [$like])->orWhereRaw('lower(coalesce(body, \'\')) like ?', [$like])->orWhereRaw('lower(coalesce(quote, \'\')) like ?', [$like]));
            });

        $findings = $query->with(['workspace:id,name,slug,key', 'task:id,workspace_id,number,title', 'author:id,name', 'linkedTasks:id,workspace_id,number,title'])
            ->latest('id')->paginate(30)->withQueryString()
            ->through(fn (Finding $f): array => $this->present($f, $user));

        // Tags in use (for the filter), most used first.
        $tags = [];
        Finding::query()->whereIn('workspace_id', $ids)->latest('id')->limit(2000)->pluck('tags')
            ->each(function ($list) use (&$tags) {
                foreach ((array) $list as $t) {
                    $tags[(string) $t] = ($tags[(string) $t] ?? 0) + 1;
                }
            });
        arsort($tags);

        return Inertia::render('findings/Index', [
            'findings' => $findings,
            'filters' => array_merge(['q' => null, 'tag' => null, 'confidence' => null, 'workspace' => null], $filters),
            'tags' => array_slice(array_keys($tags), 0, 30),
            'workspaces' => $visible->filter(fn (Workspace $w) => $this->access->canContribute($user, $w))->values()->map(fn (Workspace $w) => ['id' => $w->id, 'name' => $w->name]),
            'allWorkspaces' => $visible->map(fn (Workspace $w) => ['id' => $w->id, 'name' => $w->name])->values(),
            'confidence' => Finding::CONFIDENCE,
        ]);
    }

    public function store(Request $request): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'source_type' => ['required', Rule::in(['comment', 'annotation', 'manual'])],
            'source_id' => ['nullable', 'integer', 'required_unless:source_type,manual'],
            'workspace_id' => ['nullable', 'integer', 'required_if:source_type,manual'],
            'task_id' => ['nullable', 'integer'],
            ...$this->fieldRules(),
        ]);

        [$workspace, $taskId, $attachmentId, $quote, $page, $sourceId] = $this->resolveSource($data);
        abort_unless($this->access->canContribute($user, $workspace), 403);
        if ($sourceId !== null && Finding::query()->where('source_type', $data['source_type'])->where('source_id', $sourceId)->exists()) {
            throw ValidationException::withMessages(['source_id' => __('This is already saved as a finding.')]);
        }

        $finding = Finding::query()->create([
            'workspace_id' => $workspace->id,
            'title' => trim((string) $data['title']),
            'body' => isset($data['body']) ? trim((string) $data['body']) : null,
            'tags' => self::tags($data['tags'] ?? []),
            'confidence' => $data['confidence'],
            'source_type' => $data['source_type'],
            'source_id' => $sourceId,
            'task_id' => $taskId,
            'attachment_id' => $attachmentId,
            'quote' => $quote,
            'page' => $page,
            'created_by' => $user->id,
        ]);
        $this->activity->log('finding.created', $finding->task_id !== null ? Task::query()->find($finding->task_id) : $workspace, ['finding_id' => $finding->id, 'title' => $finding->title], $user);

        if ($request->expectsJson()) {
            return response()->json(['id' => $finding->id, 'message' => __('Saved to Findings.')], 201);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved to Findings.')]);

        return back();
    }

    public function update(Request $request, Finding $finding): RedirectResponse
    {
        $this->authorizeEdit($request, $finding);
        $data = $request->validate($this->fieldRules());
        $finding->forceFill([
            'title' => trim((string) $data['title']),
            'body' => isset($data['body']) ? trim((string) $data['body']) : null,
            'tags' => self::tags($data['tags'] ?? []),
            'confidence' => $data['confidence'],
        ])->save();

        return back();
    }

    public function destroy(Request $request, Finding $finding): RedirectResponse
    {
        $this->authorizeEdit($request, $finding);
        $finding->delete();
        $this->activity->log('finding.deleted', null, ['finding_id' => $finding->id, 'title' => $finding->title]);

        return back();
    }

    /** Link the finding to a task or decision it informs (same client). */
    public function link(Request $request, Finding $finding): RedirectResponse
    {
        $workspace = $this->workspaceFor($request, $finding, contribute: true);
        $taskId = (int) $request->validate(['task_id' => ['required', 'integer', Rule::exists('tasks', 'id')->where('workspace_id', $workspace->id)->whereNull('deleted_at')]])['task_id'];
        $finding->linkedTasks()->syncWithoutDetaching([$taskId]);

        return back();
    }

    public function unlink(Request $request, Finding $finding, Task $task): RedirectResponse
    {
        $this->workspaceFor($request, $finding, contribute: true);
        $finding->linkedTasks()->detach($task->id);

        return back();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Finding $f, User $user): array
    {
        $ws = $f->workspace;
        $taskUrl = fn (?Task $t) => $t !== null && $ws !== null ? route('workspaces.tasks.show', [$ws, $t], false) : null;
        $key = fn (Task $t) => $ws !== null && $ws->key !== null && $t->number !== null ? $ws->key.'-'.$t->number : '#'.$t->id;

        return [
            'id' => $f->id,
            'title' => $f->title,
            'body' => $f->body,
            'tags' => $f->tags,
            'confidence' => $f->confidence,
            'source_type' => $f->source_type,
            'quote' => $f->quote,
            'page' => $f->page,
            'workspace' => $ws !== null ? ['id' => $ws->id, 'name' => $ws->name] : null,
            'source' => $f->task !== null ? ['key' => $key($f->task), 'title' => $f->task->title, 'url' => $taskUrl($f->task)] : null,
            'linked' => $f->linkedTasks->map(fn (Task $t) => ['id' => $t->id, 'key' => $key($t), 'title' => $t->title, 'url' => $taskUrl($t)])->values(),
            'author' => $f->author->name ?? 'Former member',
            'created_at' => $f->created_at?->toIso8601String(),
            'can_edit' => $ws !== null && ($f->created_by === $user->id || $this->access->canLead($user, $ws)),
            'can_link' => $ws !== null && $this->access->canContribute($user, $ws),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: Workspace, 1: int|null, 2: int|null, 3: string|null, 4: int|null, 5: int|null}
     */
    private function resolveSource(array $data): array
    {
        $type = (string) $data['source_type'];
        if ($type === 'comment') {
            $comment = Comment::query()->findOrFail((int) $data['source_id']);
            $task = $comment->commentable;
            abort_unless($task instanceof Task, 404);

            return [Workspace::query()->findOrFail($task->workspace_id), $task->id, null, null, null, $comment->id];
        }
        if ($type === 'annotation') {
            $note = Annotation::query()->findOrFail((int) $data['source_id']);
            $file = Attachment::query()->findOrFail($note->attachment_id);
            $record = $file->attachable;

            return [Workspace::query()->findOrFail($file->workspace_id), $record instanceof Task ? $record->id : null, $file->id, $note->quote ?? Str::limit($note->body, 1000), $note->page, $note->id];
        }
        $workspace = Workspace::query()->findOrFail((int) $data['workspace_id']);
        $taskId = isset($data['task_id']) ? (int) $data['task_id'] : null;
        if ($taskId !== null && ! Task::query()->whereKey($taskId)->where('workspace_id', $workspace->id)->exists()) {
            throw ValidationException::withMessages(['task_id' => __('That task belongs to another client.')]);
        }

        return [$workspace, $taskId, null, null, null, null];
    }

    /**
     * @return array<string, mixed>
     */
    private function fieldRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:5000'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:30'],
            'confidence' => ['required', Rule::in(array_keys(Finding::CONFIDENCE))],
        ];
    }

    private function authorizeEdit(Request $request, Finding $finding): void
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $this->workspaceFor($request, $finding);
        abort_unless($finding->created_by === $user->id || $this->access->canLead($user, $workspace), 403);
    }

    private function workspaceFor(Request $request, Finding $finding, bool $contribute = false): Workspace
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::query()->find($finding->workspace_id);
        abort_unless($workspace !== null && ($contribute ? $this->access->canContribute($user, $workspace) : $this->access->canView($user, $workspace)), 403);

        return $workspace;
    }

    /**
     * @param  array<int, mixed>  $tags
     * @return list<string>
     */
    private static function tags(array $tags): array
    {
        return array_slice(array_values(array_unique(array_filter(array_map(fn ($t) => self::tag((string) $t), $tags)))), 0, 10);
    }

    private static function tag(string $tag): string
    {
        return Str::of($tag)->lower()->trim()->replaceMatches('/[^a-z0-9 _-]+/', '')->replaceMatches('/\s+/', '-')->limit(30, '')->toString();
    }
}
