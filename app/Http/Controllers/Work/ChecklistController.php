<?php

namespace App\Http\Controllers\Work;

use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * E-36: checklist lines on a task (definition of done). Required lines block Done.
 */
class ChecklistController extends Controller
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function store(Request $request, Workspace $workspace, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);
        $data = $request->validate(['label' => ['required', 'string', 'max:200'], 'required' => ['boolean']]);
        $task->checklistItems()->create([
            'label' => trim((string) $data['label']),
            'required' => $request->boolean('required', true),
            'position' => 1 + (int) $task->checklistItems()->max('position'),
        ]);

        return back();
    }

    public function update(Request $request, Workspace $workspace, Task $task, TaskChecklistItem $checklistItem): RedirectResponse
    {
        Gate::authorize('update', $task);
        abort_unless($checklistItem->task_id === $task->id, 404);
        $data = $request->validate(['done' => ['sometimes', 'boolean'], 'required' => ['sometimes', 'boolean'], 'label' => ['sometimes', 'string', 'max:200']]);

        if (array_key_exists('done', $data)) {
            $checklistItem->forceFill(['done_at' => $request->boolean('done') ? now() : null, 'done_by' => $request->boolean('done') ? $request->user()?->id : null]);
            $this->activity->log($request->boolean('done') ? 'task.checklist-ticked' : 'task.checklist-unticked', $task, ['item' => $checklistItem->label]);
        }
        if (array_key_exists('required', $data)) {
            $checklistItem->required = $request->boolean('required');
        }
        if (array_key_exists('label', $data)) {
            $checklistItem->label = trim((string) $data['label']);
        }
        $checklistItem->save();

        return back();
    }

    public function destroy(Workspace $workspace, Task $task, TaskChecklistItem $checklistItem): RedirectResponse
    {
        Gate::authorize('update', $task);
        abort_unless($checklistItem->task_id === $task->id, 404);
        $checklistItem->delete();

        return back();
    }
}
