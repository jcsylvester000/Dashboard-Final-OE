<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Work\TaskPresenter;
use App\Domain\Work\TaskService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Work\SaveTaskRequest;
use App\Http\Resources\V1\TaskResource;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Tasks across every workspace the token owner can open. Writes reuse the web
 * validation (SaveTaskRequest), policies and TaskService, so alerts and the
 * timeline behave exactly like the web app.
 */
class TaskController extends Controller
{
    /** Relations TaskResource prints. */
    public const WITH = ['status:id,name,category', 'department:id,name,slug', 'assignee:id,name'];

    public function __construct(private readonly TaskService $tasks, private readonly TaskPresenter $present) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $user */
        $user = $request->user();
        $f = $request->validate([
            'workspace_id' => ['nullable', 'integer'],
            'assignee' => ['nullable', 'string', 'max:20'],   // "me" or a user id
            'status_id' => ['nullable', 'integer'],
            'due' => ['nullable', Rule::in(['overdue', 'week'])],
            'include_done' => ['nullable', 'boolean'],
            'updated_since' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Task::query()
            ->with(self::WITH)
            ->whereIn('workspace_id', Workspace::query()->visibleTo($user)->select('id'))
            ->when($f['workspace_id'] ?? null, fn ($q, $id) => $q->where('workspace_id', $id))
            ->when($f['status_id'] ?? null, fn ($q, $id) => $q->where('status_id', $id))
            ->when(($f['assignee'] ?? null) === 'me', fn ($q) => $q->where('assignee_id', $user->id))
            ->when(is_numeric($f['assignee'] ?? null), fn ($q) => $q->where('assignee_id', (int) $f['assignee']))
            ->when(($f['due'] ?? null) === 'overdue', fn ($q) => $q->whereDate('due_on', '<', now()->toDateString()))
            ->when(($f['due'] ?? null) === 'week', fn ($q) => $q->whereDate('due_on', '>=', now()->toDateString())->whereDate('due_on', '<=', now()->addDays(7)->toDateString()))
            ->when(! ($f['include_done'] ?? false), fn ($q) => $q->whereNotIn('status_id', $this->present->doneStatusIds()))
            ->when($f['updated_since'] ?? null, fn ($q, $since) => $q->where('updated_at', '>=', $since))
            ->orderByRaw('due_on is null, due_on asc')
            ->orderBy('id');

        return TaskResource::collection($query->paginate((int) ($f['per_page'] ?? 50))->withQueryString());
    }

    public function show(Task $task): TaskResource
    {
        Gate::authorize('view', $task);

        return new TaskResource($task->load(self::WITH));
    }

    public function store(SaveTaskRequest $request, Workspace $workspace): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $task = $this->tasks->create($workspace, $request->validated(), $user);

        return (new TaskResource($task->load(self::WITH)))->response()->setStatusCode(201);
    }

    public function update(SaveTaskRequest $request, Workspace $workspace, Task $task): TaskResource
    {
        /** @var User $user */
        $user = $request->user();
        $this->tasks->update($task, $request->validated(), $user);

        return new TaskResource($task->refresh()->load(self::WITH));
    }
}
