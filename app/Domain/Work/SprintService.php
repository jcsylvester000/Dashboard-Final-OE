<?php

namespace App\Domain\Work;

use App\Domain\Identity\ActivityLogger;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Sprint lifecycle: plan -> start (points committed) -> close (report + carry-over).
 */
class SprintService
{
    public function __construct(private readonly ActivityLogger $activity) {}

    public function start(Sprint $sprint, User $actor): Sprint
    {
        if ($sprint->status !== Sprint::PLANNED) {
            throw ValidationException::withMessages(['sprint' => __('Only a planned sprint can be started.')]);
        }
        if (Sprint::query()->where('workspace_id', $sprint->workspace_id)->where('status', Sprint::ACTIVE)->exists()) {
            throw ValidationException::withMessages(['sprint' => __('Close the active sprint first. A client runs one sprint at a time.')]);
        }
        if (! $sprint->tasks()->exists()) {
            throw ValidationException::withMessages(['sprint' => __('Add tasks from the backlog before starting.')]);
        }

        $sprint->forceFill([
            'status' => Sprint::ACTIVE,
            'started_at' => now(),
            'committed_points' => $this->points($sprint),
        ])->save();

        $this->activity->log('sprint.started', $sprint, ['committed_points' => $sprint->committed_points], $actor);

        return $sprint;
    }

    /**
     * Close the sprint. Unfinished tasks move to $next (a planned sprint of the
     * same client) or back to the backlog.
     */
    public function close(Sprint $sprint, User $actor, ?Sprint $next = null): Sprint
    {
        if ($sprint->status !== Sprint::ACTIVE) {
            throw ValidationException::withMessages(['sprint' => __('Only the active sprint can be closed.')]);
        }
        if ($next !== null && ($next->workspace_id !== $sprint->workspace_id || $next->status !== Sprint::PLANNED)) {
            throw ValidationException::withMessages(['next_sprint_id' => __('Carry over into a planned sprint of this client.')]);
        }

        return DB::transaction(function () use ($sprint, $actor, $next) {
            $completed = (int) $sprint->tasks()->whereNotNull('completed_at')->sum('story_points');
            $carried = $sprint->tasks()->whereNull('completed_at')->update(['sprint_id' => $next?->id]);

            $sprint->forceFill([
                'status' => Sprint::CLOSED,
                'closed_at' => now(),
                'completed_points' => $completed,
                'carried_over' => $carried,
            ])->save();

            $this->activity->log('sprint.closed', $sprint, [
                'completed_points' => $completed,
                'carried_over' => $carried,
                'next_sprint_id' => $next?->id,
            ], $actor);

            return $sprint;
        });
    }

    /**
     * Burndown for the sprint: remaining story points at the end of each day
     * (falls back to task counts when nothing is pointed), plus the ideal line.
     * Remaining = current scope minus work completed by that day.
     *
     * @return array{unit: string, scope: int, days: list<array{date: string, ideal: float, remaining: int|null}>}
     */
    public function burndown(Sprint $sprint): array
    {
        $tasks = $sprint->tasks()->get(['id', 'story_points', 'completed_at']);
        $pointed = $tasks->sum(fn (Task $t) => (int) $t->story_points) > 0;
        $weight = fn (Task $t): int => $pointed ? (int) $t->story_points : 1;
        $scope = (int) $tasks->sum($weight);

        $start = CarbonImmutable::parse($sprint->starts_on->toDateString());
        $end = CarbonImmutable::parse($sprint->ends_on->toDateString());
        $today = CarbonImmutable::parse(now()->toDateString());
        $span = max(1, (int) $start->diffInDays($end));

        $days = [];
        for ($d = $start, $i = 0; $d->lte($end); $d = $d->addDay(), $i++) {
            $done = $tasks->filter(fn (Task $t) => $t->completed_at !== null && $t->completed_at->toDateString() <= $d->toDateString())->sum($weight);
            $days[] = [
                'date' => $d->toDateString(),
                'ideal' => round($scope - $scope * $i / $span, 1),
                'remaining' => $d->lte($today) || $sprint->isClosed() ? $scope - (int) $done : null,
            ];
        }

        return ['unit' => $pointed ? 'points' : 'tasks', 'scope' => $scope, 'days' => $days];
    }

    public function points(Sprint $sprint): int
    {
        return (int) $sprint->tasks()->sum('story_points');
    }
}
