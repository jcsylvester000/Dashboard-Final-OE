<?php

namespace App\Http\Controllers\Work;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * E-58: snooze this task's alerts for yourself (they still arrive, snoozed until then).
 */
class TaskMuteController extends Controller
{
    public function store(Request $request, Workspace $workspace, Task $task): RedirectResponse
    {
        Gate::authorize('view', $workspace);
        $for = (string) $request->validate(['for' => ['required', Rule::in(['1h', 'tomorrow', 'next_week'])]])['for'];
        /** @var User $user */
        $user = $request->user();
        $until = match ($for) {
            '1h' => now()->addHour(),
            'tomorrow' => now()->addDay()->setTime(9, 0),
            default => now()->next('Monday')->setTime(9, 0),
        };
        DB::table('task_mutes')->updateOrInsert(['user_id' => $user->id, 'task_id' => $task->id], ['until' => $until, 'updated_at' => now(), 'created_at' => now()]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alerts about this task are snoozed until :t.', ['t' => $until->format('D g:i A')])]);

        return back();
    }

    public function destroy(Request $request, Workspace $workspace, Task $task): RedirectResponse
    {
        Gate::authorize('view', $workspace);
        DB::table('task_mutes')->where('user_id', $request->user()?->id)->where('task_id', $task->id)->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Alerts about this task are back on.')]);

        return back();
    }
}
