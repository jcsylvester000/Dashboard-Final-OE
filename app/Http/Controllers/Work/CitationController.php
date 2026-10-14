<?php

namespace App\Http\Controllers\Work;

use App\Domain\Identity\ActivityLogger;
use App\Domain\Work\LinkPreview;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskCitation;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * E-29: paste a link on a task and it becomes a citation (title, site, date read).
 */
class CitationController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function store(Request $request, Workspace $workspace, Task $task, LinkPreview $preview): RedirectResponse
    {
        Gate::authorize('update', $task);
        $url = trim((string) $request->validate(['url' => ['required', 'url:http,https', 'max:2048']])['url']);
        /** @var User $user */
        $user = $request->user();
        $meta = $preview->fetch($url);
        $citation = TaskCitation::query()->create([
            'task_id' => $task->id, 'url' => $url, 'title' => $meta['title'], 'site' => $meta['site'],
            'accessed_at' => now(), 'created_by' => $user->id,
        ]);
        $this->activity->log('task.source-added', $task, ['citation_id' => $citation->id, 'site' => $citation->site], $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => $citation->title !== null ? __('Source added: :t', ['t' => $citation->title]) : __('Source added (the site did not share a title).')]);

        return back();
    }

    public function destroy(Workspace $workspace, Task $task, TaskCitation $citation): RedirectResponse
    {
        Gate::authorize('update', $task);
        abort_unless($citation->task_id === $task->id, 404);
        $citation->delete();

        return back();
    }
}
