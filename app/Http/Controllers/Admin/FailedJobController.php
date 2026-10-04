<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use stdClass;

/**
 * Failed background jobs (alerts, broadcasts): see why, retry or discard.
 */
class FailedJobController extends Controller
{
    public function index(): Response
    {
        $page = DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->paginate(25, ['uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at']);

        return Inertia::render('admin/failed-jobs/Index', [
            'jobs' => $page->through(function (stdClass $row): array {
                /** @var array{displayName?: string} $payload */
                $payload = json_decode((string) $row->payload, true) ?: [];

                return [
                    'uuid' => (string) $row->uuid,
                    'job' => class_basename($payload['displayName'] ?? 'Job'),
                    'queue' => $row->connection.' / '.$row->queue,
                    // First line only: full traces can contain data we don't want on screen.
                    'error' => Str::limit(strtok((string) $row->exception, "\n") ?: '', 300),
                    'failed_at' => (string) $row->failed_at,
                ];
            }),
        ]);
    }

    public function retry(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $ids = $this->ids($request);
        Artisan::call('queue:retry', ['id' => $ids === [] ? ['all'] : $ids]);
        $activity->log('jobs.retried', null, ['count' => $ids === [] ? 'all' : count($ids)]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Queued for retry.')]);

        return back();
    }

    public function destroy(Request $request, ActivityLogger $activity): RedirectResponse
    {
        $ids = $this->ids($request);
        $query = DB::table('failed_jobs');
        $deleted = $ids === [] ? $query->delete() : $query->whereIn('uuid', $ids)->delete();
        $activity->log('jobs.discarded', null, ['count' => $deleted]);

        return back();
    }

    /**
     * Selected job uuids; empty list means "all".
     *
     * @return list<string>
     */
    private function ids(Request $request): array
    {
        $data = $request->validate([
            'ids' => ['present', 'array', 'max:200'],
            'ids.*' => ['string', 'uuid'],
        ]);

        return array_values($data['ids']);
    }
}
