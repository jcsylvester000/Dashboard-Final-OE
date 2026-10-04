<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ActivityLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'actor' => ['nullable', 'integer'],
        ]);

        $logs = ActivityLog::query()
            ->with(['actor:id,name', 'subject'])
            ->when($filters['action'] ?? null, fn (Builder $q, string $a) => $q->where('action', 'like', $a.'%'))
            ->when($filters['actor'] ?? null, fn (Builder $q, $id) => $q->where('actor_id', (int) $id))
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
        ]);
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
