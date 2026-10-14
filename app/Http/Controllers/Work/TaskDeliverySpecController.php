<?php

namespace App\Http\Controllers\Work;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * E-18: choose which delivery spec this task's videos are checked against
 * (empty = match the client's specs by the task's Channels and Deliverable).
 */
class TaskDeliverySpecController extends Controller
{
    public function update(Request $request, Workspace $workspace, Task $task): RedirectResponse
    {
        Gate::authorize('update', $task);
        $data = $request->validate([
            'delivery_spec_id' => ['nullable', 'integer', Rule::exists('delivery_specs', 'id')->where('workspace_id', $workspace->id)],
        ]);
        $task->forceFill(['delivery_spec_id' => $data['delivery_spec_id'] ?? null])->save();

        return back();
    }
}
