<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Global search (brutal scenario J3): one box for tasks, projects and clients
 * across every client the person can open. Understands task keys (BRW-24),
 * #ids and a few filters:
 *   type:bug  priority:urgent  @me  in:BRW  open
 */
class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $input = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            // Pickers (e.g. "wait on another task", S1-10): one client, skip a task, open work only.
            'workspace' => ['nullable', 'integer'],
            'exclude' => ['nullable', 'integer'],
        ]);
        $q = trim((string) ($input['q'] ?? ''));

        $parsed = self::parse($q);
        $visible = Workspace::query()->visibleTo($user)->select('id');
        $key = $parsed['key'];

        $query = Task::query()
            ->with(['workspace:id,name,slug,key', 'status:id,name,color,category', 'assignee:id,name'])
            ->whereIn('workspace_id', $visible)
            ->when($input['workspace'] ?? null, fn (Builder $b, $id) => $b->where('workspace_id', (int) $id))
            ->when($input['exclude'] ?? null, fn (Builder $b, $id) => $b->whereKeyNot((int) $id));
        if (isset($input['workspace'])) {
            $parsed['open'] = true;
        }

        if ($key !== null) {
            $query->where('number', $key[1])->whereHas('workspace', fn (Builder $w) => $w->where('key', $key[0]));
        } elseif ($parsed['id'] !== null) {
            $query->whereKey($parsed['id']);
        } elseif ($parsed['text'] !== '') {
            $query->whereRaw('lower(title) like ?', ['%'.mb_strtolower($parsed['text']).'%']);
        } elseif (! $parsed['filtered'] && ! isset($input['workspace'])) {
            // Empty box: my most recently touched open work (pickers show the client's recent open work).
            $query->where('assignee_id', $user->id);
            $parsed['open'] = true;
        }

        if ($parsed['type'] !== null) {
            $query->where('type', $parsed['type']);
        }
        if ($parsed['priority'] !== null) {
            $query->where('priority', $parsed['priority']);
        }
        if ($parsed['mine']) {
            $query->where('assignee_id', $user->id);
        }
        if ($parsed['in'] !== null) {
            $in = $parsed['in'];
            $query->whereHas('workspace', fn (Builder $w) => $w->where('key', $in));
        }
        if ($parsed['open']) {
            $query->whereNotIn('status_id', TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id'));
        }

        $tasks = $query->orderByRaw('completed_at is not null')->latest('updated_at')->limit(10)->get();

        // Projects and clients only for plain text searches.
        $plain = $parsed['text'] !== '' && ! $parsed['filtered'];
        $like = '%'.mb_strtolower($parsed['text']).'%';

        $projects = $plain ? Project::query()->with('workspace:id,name,slug')
            ->whereIn('workspace_id', $visible)
            ->whereRaw('lower(name) like ?', [$like])
            ->orderBy('name')->limit(5)->get() : collect();

        $clients = $plain ? Workspace::query()->visibleTo($user)
            ->where(fn (Builder $b) => $b->whereRaw('lower(name) like ?', [$like])->orWhere('key', strtoupper($parsed['text'])))
            ->orderBy('name')->limit(5)->get(['id', 'name', 'slug', 'key']) : collect();

        return response()->json([
            'query' => $q,
            'tasks' => $tasks->map(fn (Task $t) => [
                'id' => $t->id,
                'key' => $t->keyLabel(),
                'type' => $t->type,
                'title' => $t->title,
                'status' => $t->status->only(['name', 'color', 'category']),
                'assignee' => $t->assignee?->name,
                'workspace' => $t->workspace->name,
                'url' => route('workspaces.tasks.show', [$t->workspace->slug, $t->id], false),
            ])->values(),
            'projects' => $projects->map(fn (Project $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'workspace' => $p->workspace->name,
                'url' => route('workspaces.projects.show', [$p->workspace->slug, $p->id], false),
            ])->values(),
            'clients' => $clients->map(fn (Workspace $w) => [
                'id' => $w->id,
                'name' => $w->name,
                'key' => $w->key,
                'url' => route('workspaces.tasks.index', [$w->slug], false),
            ])->values(),
        ]);
    }

    /**
     * Short link: /t/BRW-24 opens the task (for chat messages and commits).
     */
    public function open(Request $request, string $key): RedirectResponse
    {
        $task = Task::findByKey($key);
        abort_if($task === null, 404);
        $task->load('workspace');
        Gate::authorize('view', $task);

        return to_route('workspaces.tasks.show', [$task->workspace, $task]);
    }

    /**
     * @return array{text: string, key: array{0: string, 1: int}|null, id: int|null, type: string|null, priority: string|null, mine: bool, in: string|null, open: bool, filtered: bool}
     */
    public static function parse(string $q): array
    {
        $out = ['text' => '', 'key' => null, 'id' => null, 'type' => null, 'priority' => null, 'mine' => false, 'in' => null, 'open' => false, 'filtered' => false];
        $words = [];

        foreach (preg_split('/\s+/', $q) ?: [] as $token) {
            $lower = mb_strtolower($token);
            if ($token === '') {
                continue;
            } elseif (preg_match('/^([A-Za-z][A-Za-z0-9]{1,5})-(\d{1,9})$/', $token, $m)) {
                $out['key'] = [strtoupper($m[1]), (int) $m[2]];
            } elseif (preg_match('/^#(\d{1,9})$/', $token, $m)) {
                $out['id'] = (int) $m[1];
            } elseif (str_starts_with($lower, 'type:') && array_key_exists(substr($lower, 5), Task::TYPES)) {
                $out['type'] = substr($lower, 5);
            } elseif (str_starts_with($lower, 'priority:') && array_key_exists(substr($lower, 9), Task::PRIORITIES)) {
                $out['priority'] = substr($lower, 9);
            } elseif ($lower === '@me') {
                $out['mine'] = true;
            } elseif (str_starts_with($lower, 'in:') && strlen($lower) > 3) {
                $out['in'] = strtoupper(substr($token, 3));
            } elseif ($lower === 'open') {
                $out['open'] = true;
            } else {
                $words[] = $token;
            }
        }

        $out['text'] = implode(' ', $words);
        $out['filtered'] = $out['type'] !== null || $out['priority'] !== null || $out['mine'] || $out['in'] !== null || $out['open'];

        return $out;
    }
}
