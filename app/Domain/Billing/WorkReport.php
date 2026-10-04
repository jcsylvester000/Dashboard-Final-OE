<?php

namespace App\Domain\Billing;

use App\Models\Invoice;
use App\Models\InvoiceItemSource;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;

/**
 * The work transparency report sent with an invoice: what was done in the
 * period and who did it, built from the exact time entries the invoice bills
 * (so its hours always equal the invoice's hours). Marketing and SEO tasks get
 * their own sections with campaign / channel / deliverable and URL / keyword / work type.
 */
class WorkReport
{
    /**
     * @return array{total_minutes: int, tasks: list<array<string, mixed>>, departments: list<array<string, mixed>>, marketing: list<array<string, mixed>>, seo: list<array<string, mixed>>, completed_without_time: list<array<string, mixed>>}
     */
    public function build(Invoice $invoice): array
    {
        /** @var array<int, int> $minutesByEntry minutes billed per time entry (retainer + overage parts summed) */
        $minutesByEntry = [];
        InvoiceItemSource::query()
            ->whereIn('invoice_item_id', $invoice->items()->select('id'))
            ->where('source_type', (new TimeEntry)->getMorphClass())
            ->get(['source_id', 'minutes'])
            ->each(function (InvoiceItemSource $s) use (&$minutesByEntry) {
                $minutesByEntry[$s->source_id] = ($minutesByEntry[$s->source_id] ?? 0) + (int) $s->minutes;
            });

        return $this->fromEntries($minutesByEntry, $invoice->workspace_id, $invoice->period_start?->toImmutable(), $invoice->period_end?->toImmutable());
    }

    /**
     * Work Summary for any date range (P6-15/16): every finished time entry in the range,
     * billable or not, organised the same way as the invoice work report.
     *
     * @return array{total_minutes: int, tasks: list<array<string, mixed>>, departments: list<array<string, mixed>>, marketing: list<array<string, mixed>>, seo: list<array<string, mixed>>, completed_without_time: list<array<string, mixed>>}
     */
    public function forPeriod(int $workspaceId, CarbonImmutable $from, CarbonImmutable $to, ?string $departmentSlug = null): array
    {
        $minutesByEntry = TimeEntry::query()
            ->where('workspace_id', $workspaceId)
            ->where(fn ($q) => $q->whereNull('started_at')->orWhereNotNull('ended_at'))
            ->whereDate('entry_date', '>=', $from->toDateString())
            ->whereDate('entry_date', '<=', $to->toDateString())
            ->when($departmentSlug !== null, fn ($q) => $q->whereHas('department', fn ($d) => $d->where('slug', $departmentSlug)))
            ->pluck('minutes', 'id')
            ->map(fn ($m) => (int) $m)
            ->all();

        return $this->fromEntries($minutesByEntry, $workspaceId, $from, $to);
    }

    /**
     * @param  array<int, int>  $minutesByEntry  time entry id => minutes to count
     * @return array{total_minutes: int, tasks: list<array<string, mixed>>, departments: list<array<string, mixed>>, marketing: list<array<string, mixed>>, seo: list<array<string, mixed>>, completed_without_time: list<array<string, mixed>>}
     */
    private function fromEntries(array $minutesByEntry, int $workspaceId, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $entries = TimeEntry::query()
            ->withTrashed()
            ->with(['user:id,name', 'department:id,name', 'task' => fn ($q) => $q->withTrashed()->with(['department:id,name,slug', 'status:id,name'])])
            ->whereIn('id', array_keys($minutesByEntry))
            ->get();

        $tasks = [];
        $departments = [];
        foreach ($entries as $entry) {
            $minutes = $minutesByEntry[$entry->id] ?? 0;
            $person = $entry->user->name ?? 'Former member';
            $task = $entry->task;
            $deptName = $task?->department->name ?? $entry->department->name ?? 'General';

            $key = $task !== null ? 't'.$task->id : 'n'.($entry->department_id ?? 0);
            $tasks[$key] ??= [
                'id' => $task?->id,
                'title' => $task !== null ? $task->title : 'Time not linked to a task',
                'department' => $deptName,
                'department_slug' => $task?->department?->slug,
                'status' => $task?->status->name,
                'completed_on' => $task?->completed_at?->toDateString(),
                'work_details' => $task !== null ? ($task->work_details ?? []) : [],
                'minutes' => 0,
                'people' => [],
            ];
            $tasks[$key]['minutes'] += $minutes;
            $tasks[$key]['people'][$person] = ($tasks[$key]['people'][$person] ?? 0) + $minutes;

            $departments[$deptName] ??= ['name' => $deptName, 'minutes' => 0, 'people' => []];
            $departments[$deptName]['minutes'] += $minutes;
            $departments[$deptName]['people'][$person] = ($departments[$deptName]['people'][$person] ?? 0) + $minutes;
        }

        $taskRows = array_values(array_map(function (array $t): array {
            arsort($t['people']);
            $t['people'] = array_map(fn ($name, $m) => ['name' => (string) $name, 'minutes' => $m], array_keys($t['people']), $t['people']);

            return $t;
        }, $tasks));
        usort($taskRows, fn (array $a, array $b) => [$a['department'], $b['minutes']] <=> [$b['department'], $a['minutes']]);

        $deptRows = array_values(array_map(function (array $d): array {
            arsort($d['people']);
            $d['people'] = array_map(fn ($name, $m) => ['name' => (string) $name, 'minutes' => $m], array_keys($d['people']), $d['people']);

            return $d;
        }, $departments));
        usort($deptRows, fn (array $a, array $b) => $b['minutes'] <=> $a['minutes']);

        $section = fn (string $slug, array $fields) => array_values(array_map(
            fn (array $t) => [
                'title' => $t['title'],
                'status' => $t['status'],
                'minutes' => $t['minutes'],
                ...array_combine($fields, array_map(fn ($f) => $t['work_details'][$f] ?? null, $fields)),
            ],
            array_filter($taskRows, fn (array $t) => $t['department_slug'] === $slug),
        ));

        return [
            'total_minutes' => array_sum($minutesByEntry),
            'tasks' => $taskRows,
            'departments' => $deptRows,
            'marketing' => $section('marketing', array_keys(Task::WORK_DETAIL_FIELDS['marketing'])),
            'seo' => $section('seo', array_keys(Task::WORK_DETAIL_FIELDS['seo'])),
            'completed_without_time' => $this->completedWithoutTime($workspaceId, $from, $to, array_filter(array_column($taskRows, 'id'))),
        ];
    }

    /**
     * Tasks finished in the period that carried no billed time (still part of "what we did").
     *
     * @param  array<int, int|null>  $exclude
     * @return list<array<string, mixed>>
     */
    private function completedWithoutTime(int $workspaceId, ?CarbonImmutable $from, ?CarbonImmutable $to, array $exclude): array
    {
        if ($from === null || $to === null) {
            return [];
        }

        $done = TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id')->all();

        return Task::query()
            ->with('department:id,name')
            ->where('workspace_id', $workspaceId)
            ->whereIn('status_id', $done)
            ->whereDate('completed_at', '>=', $from->toDateString())
            ->whereDate('completed_at', '<=', $to->toDateString())
            ->whereNotIn('id', $exclude)
            ->orderBy('completed_at')
            ->limit(100)
            ->get()
            ->map(fn (Task $t): array => [
                'title' => $t->title,
                'department' => $t->department->name ?? 'General',
                'completed_on' => $t->completed_at?->toDateString(),
            ])
            ->values()
            ->all();
    }
}
