<?php

namespace App\Domain\Documents;

use App\Domain\Billing\WorkReport;
use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Models\ReportPack;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * E-51: build a client's scheduled report pack as one PDF, save it to the client's
 * files and alert the recipients (no email). Weekly packs cover the last 7 days,
 * monthly packs the last month.
 */
class ReportPacks
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly WorkReport $work,
        private readonly Notifier $notifier,
    ) {}

    public static function nextRun(string $frequency, int $day, CarbonImmutable $after): CarbonImmutable
    {
        if ($frequency === 'weekly') {
            $d = $after->addDay();
            while ($d->dayOfWeekIso !== max(1, min(7, $day))) {
                $d = $d->addDay();
            }

            return $d;
        }
        $day = max(1, min(28, $day));
        $candidate = $after->startOfMonth()->addDays($day - 1);

        return $candidate->greaterThan($after) ? $candidate : $candidate->addMonthNoOverflow();
    }

    public function run(ReportPack $pack, ?User $by = null): void
    {
        $workspace = Workspace::query()->findOrFail($pack->workspace_id);
        $to = CarbonImmutable::today()->subDay();
        $from = $pack->frequency === 'weekly' ? $to->subDays(6) : $to->subMonthNoOverflow()->addDay();
        $data = $this->data($pack, $workspace, $from, $to);
        $bytes = $this->pdf->render('pdf.report-pack', $data);
        $file = $this->pdf->save($workspace, $workspace->id, $pack->name.' '.$from->format('Y-m-d').' to '.$to->format('Y-m-d'), $bytes, $by);

        $pack->forceFill([
            'last_run_at' => now(),
            'next_run_on' => self::nextRun($pack->frequency, $pack->day, CarbonImmutable::today())->toDateString(),
            'runs' => $pack->runs + 1,
        ])->save();

        $ids = $pack->recipient_ids !== [] ? $pack->recipient_ids
            : $workspace->members()->wherePivotIn('role', [Workspace::ROLE_LEAD, Workspace::ROLE_OWNER])->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        $this->notifier->send($ids, NotificationKind::Status, $workspace, [
            'title' => sprintf('Report pack ready: %s', $pack->name),
            'body' => sprintf('%s, %s to %s (%s in the client\'s files)', $workspace->name, $from->format('M j'), $to->format('M j, Y'), $file->original_name),
            'url' => route('workspaces.show', $workspace, false),
        ], $by);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(ReportPack $pack, Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sections = $pack->sections;
        $done = TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id')->all();
        $open = Task::query()->where('workspace_id', $workspace->id)->whereNotIn('status_id', $done);
        $completed = in_array('completed', $sections, true) || in_array('estimates', $sections, true)
            ? Task::query()->where('workspace_id', $workspace->id)->whereNotNull('completed_at')
                ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])->orderBy('completed_at')->limit(300)->get(['id', 'number', 'title', 'completed_at', 'estimate_minutes'])
            : collect();
        $logged = $completed->isNotEmpty()
            ? TimeEntry::query()->whereIn('task_id', $completed->pluck('id'))->groupBy('task_id')->selectRaw('task_id, sum(minutes) as m')->pluck('m', 'task_id')
            : collect();
        $statusNames = TaskStatus::ordered()->pluck('name', 'id');

        return [
            'pack' => $pack,
            'workspace' => $workspace,
            'from' => $from,
            'to' => $to,
            'sections' => $sections,
            'hours' => in_array('hours', $sections, true) ? $this->work->forPeriod($workspace->id, $from, $to) : null,
            'completed' => $completed->map(fn (Task $t) => [
                'key' => $workspace->key !== null && $t->number !== null ? $workspace->key.'-'.$t->number : '#'.$t->id,
                'title' => $t->title, 'on' => $t->completed_at?->format('M j'),
                'estimate' => (int) $t->estimate_minutes, 'actual' => (int) ($logged[$t->id] ?? 0),
            ])->all(),
            'open' => in_array('open', $sections, true)
                ? (clone $open)->selectRaw('status_id, count(*) as c')->groupBy('status_id')->pluck('c', 'status_id')->mapWithKeys(fn ($c, $id) => [(string) ($statusNames[$id] ?? 'Other') => (int) $c])->all()
                : [],
            'overdue' => in_array('overdue', $sections, true)
                ? (clone $open)->whereNotNull('due_on')->whereDate('due_on', '<', CarbonImmutable::today()->toDateString())->orderBy('due_on')->limit(100)->get(['number', 'title', 'due_on'])
                    ->map(fn (Task $t) => ['key' => $workspace->key !== null && $t->number !== null ? $workspace->key.'-'.$t->number : '', 'title' => $t->title, 'due' => $t->due_on?->format('M j')])->all()
                : [],
        ];
    }
}
