<?php

namespace App\Http\Controllers\Work;

use App\Domain\Work\LinkPreview;
use App\Domain\Work\PreviewChecks;
use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * E-26: set (or clear) the task's preview link; it is checked right away.
 */
class TaskPreviewController extends Controller
{
    public function update(Request $request, Workspace $workspace, Task $task, PreviewChecks $checks, LinkPreview $http): RedirectResponse
    {
        Gate::authorize('update', $task);
        $url = $request->validate(['preview_url' => ['nullable', 'url:http,https', 'max:2048']])['preview_url'] ?? null;
        $url = is_string($url) ? trim($url) : null;
        if ($url !== null && $url !== '' && ! $http->isPublic($url)) {
            throw ValidationException::withMessages(['preview_url' => __('Use a public preview link (private network addresses are not checked).')]);
        }
        $task->forceFill([
            'preview_url' => $url ?: null, 'preview_status' => null, 'preview_http' => null, 'preview_checked_at' => null, 'preview_alerted_at' => null,
        ])->save();
        if ($task->preview_url !== null) {
            $status = $checks->check($task);
            Inertia::flash('toast', $status === 'up'
                ? ['type' => 'success', 'message' => __('Preview link saved. It answers.')]
                : ['type' => 'warning', 'message' => __('Preview link saved, but it does not answer right now.')]);
        }

        return back();
    }
}
