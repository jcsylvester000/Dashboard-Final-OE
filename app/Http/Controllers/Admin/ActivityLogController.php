<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\ActivityLogger;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Audit log viewer. E-91: date range and subject filters, CSV export of exactly the
 * filtered rows, and before/after values shown side by side. Rows older than the
 * retention period are moved to the archive by activity:archive.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $this->filters($request);

        $logs = $this->query($filters)
            ->with(['actor:id,name', 'subject'])
            ->latest('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (ActivityLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'actor' => $log->actor?->name,
                'subject' => $this->describeSubject($log),
                'properties' => $log->properties,
                'ip' => $log->ip_address,
                'at' => $log->created_at->toIso8601String(),
            ]);

        return Inertia::render('admin/activity/Index', [
            'logs' => $logs,
            'filters' => $filters,
            'actions' => ActivityLog::query()->distinct()->orderBy('action')->pluck('action'),
            'actors' => User::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name]),
            'subjects' => ActivityLog::query()->whereNotNull('subject_type')->distinct()->orderBy('subject_type')->pluck('subject_type')
                ->map(fn (string $t) => ['value' => $t, 'label' => class_basename($t)])->values(),
            'retentionMonths' => (int) config('security.activity_retention_months', 24),
        ]);
    }

    /** CSV of the rows matching the current filter (streamed, so large logs don't run out of memory). */
    public function export(Request $request, ActivityLogger $activity): StreamedResponse
    {
        $filters = $this->filters($request);
        $activity->log('activity.exported', null, ['filters' => $filters]);
        $query = $this->query($filters)->with(['actor:id,name'])->orderBy('id');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, ['id', 'when', 'who', 'action', 'subject_type', 'subject_id', 'details', 'ip', 'user_agent']);
            $query->chunkById(1000, function ($rows) use ($out) {
                foreach ($rows as $log) {
                    /** @var ActivityLog $log */
                    fputcsv($out, [
                        $log->id, $log->created_at->toIso8601String(), $log->actor->name ?? 'System', $log->action,
                        $log->subject_type !== null ? class_basename($log->subject_type) : '', $log->subject_id ?? '',
                        $log->properties !== null ? (string) json_encode($log->properties, JSON_UNESCAPED_UNICODE) : '',
                        $log->ip_address ?? '', $log->user_agent ?? '',
                    ]);
                }
            });
            fclose($out);
        }, 'activity-log-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return array{action?: string, actor?: int, subject?: string, subject_id?: int, from?: string, to?: string}
     */
    private function filters(Request $request): array
    {
        /** @var array{action?: string, actor?: int, subject?: string, subject_id?: int, from?: string, to?: string} $filters */
        $filters = array_filter($request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'actor' => ['nullable', 'integer'],
            'subject' => ['nullable', 'string', 'max:150'],
            'subject_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]), fn ($v) => $v !== null && $v !== '');

        return $filters;
    }

    /**
     * @param  array{action?: string, actor?: int, subject?: string, subject_id?: int, from?: string, to?: string}  $filters
     * @return Builder<ActivityLog>
     */
    private function query(array $filters): Builder
    {
        return ActivityLog::query()
            ->when($filters['action'] ?? null, fn (Builder $q, string $a) => $q->where('action', 'like', $a.'%'))
            ->when($filters['actor'] ?? null, fn (Builder $q, $id) => $q->where('actor_id', (int) $id))
            ->when($filters['subject'] ?? null, fn (Builder $q, string $t) => $q->where('subject_type', $t))
            ->when($filters['subject_id'] ?? null, fn (Builder $q, $id) => $q->where('subject_id', (int) $id))
            ->when($filters['from'] ?? null, fn (Builder $q, string $d) => $q->where('created_at', '>=', CarbonImmutable::parse($d)->startOfDay()))
            ->when($filters['to'] ?? null, fn (Builder $q, string $d) => $q->where('created_at', '<', CarbonImmutable::parse($d)->addDay()->startOfDay()));
    }

    private function describeSubject(ActivityLog $log): ?string
    {
        $subject = $log->subject;
        if ($subject === null) {
            return $log->subject_type ? class_basename($log->subject_type).' #'.$log->subject_id : null;
        }

        $label = $subject->getAttribute('name') ?? $subject->getAttribute('email');

        return class_basename($subject).($label ? ': '.$label : ' #'.$subject->getKey());
    }
}
