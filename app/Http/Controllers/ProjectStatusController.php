<?php

namespace App\Http\Controllers;

use App\Domain\Settings\CompanyProfile;
use App\Models\Project;
use App\Models\Task;
use Illuminate\View\View;

/**
 * B-06: read-only status page a client opens from a link (/p/{token}). No login.
 * Shows progress, milestones, what's coming up and what was finished recently.
 * Never shows people's names, hours, rates, comments or internal notes.
 * Unknown, disabled or expired links are a plain 404 so they can't be probed.
 */
class ProjectStatusController extends Controller
{
    public function __invoke(string $token): View
    {
        abort_unless(strlen($token) === 40 && ctype_alnum($token), 404);

        $project = Project::query()->with('workspace:id,name,deleted_at,status_labels')->where('status_token', $token)->first();
        abort_if($project === null || $project->workspace === null || $project->trashed(), 404);
        abort_if($project->status_token_expires_at !== null && $project->status_token_expires_at->isPast(), 404);
        $project->increment('status_views');

        $tasks = Task::query()->with('status:id,name,category')->where('project_id', $project->id)->whereNull('parent_id')->get();
        $done = $tasks->whereNotNull('completed_at');
        $today = now()->toDateString();
        $workspace = $project->workspace;

        return view('project-status', [
            'project' => $project,
            'client' => $workspace->name,
            // E-47: the agency's logo on the client page (embedded, no extra request).
            'logo' => app(CompanyProfile::class)->logoDataUri(),
            'agency' => app(CompanyProfile::class)->get()['name'],
            'total' => $tasks->count(),
            'done' => $done->count(),
            'percent' => $tasks->count() > 0 ? (int) round($done->count() / $tasks->count() * 100) : 0,
            'milestones' => $tasks->where('type', 'milestone')->sortBy(fn (Task $t) => $t->due_on?->toDateString() ?? '9999')->values(),
            'upcoming' => $tasks->whereNull('completed_at')->where('type', '!=', 'milestone')
                ->filter(fn (Task $t) => $t->due_on !== null && $t->due_on->toDateString() <= now()->addDays(14)->toDateString())
                ->sortBy(fn (Task $t) => $t->due_on?->toDateString())->take(12)->values(),
            'recent' => $done->filter(fn (Task $t) => $t->completed_at !== null && $t->completed_at->gte(now()->subDays(14)))
                ->sortByDesc('completed_at')->take(10)->values(),
            'today' => $today,
            // R-22: "simple" (default) speaks client language and hides overdue marks;
            // "detailed" shows our status names (with this client's labels) and late dates.
            'detailed' => $project->status_detail === 'detailed',
            'stateLabel' => function (Task $t) use ($project, $workspace): string {
                if ($project->status_detail === 'detailed') {
                    return $workspace->statusLabel($t->status_id, $t->status->name);
                }

                return match ($t->status->category) {
                    'done' => __('Done'),
                    'active', 'blocked' => __('In progress'),
                    default => __('Planned'),
                };
            },
        ]);
    }
}
