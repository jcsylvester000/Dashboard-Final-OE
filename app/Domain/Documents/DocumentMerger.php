<?php

namespace App\Domain\Documents;

use App\Domain\Settings\CompanyProfile;
use App\Models\DocumentTemplate;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * E-52 (D-06: PDF only): fill a Markdown document template with client and project
 * merge fields, then render it as a branded PDF with page numbers and save it to the
 * project. Unknown fields stay visible as [unknown: name] so nothing is silently lost.
 */
class DocumentMerger
{
    /** field => description, shown in the template editor */
    public const FIELDS = [
        'client.name' => 'Client name',
        'client.key' => 'Client task key',
        'client.website' => 'Client website',
        'client.contact' => 'Client primary contact',
        'project.name' => 'Project name',
        'project.description' => 'Project description',
        'project.start_on' => 'Project start date',
        'project.due_on' => 'Project due date',
        'project.lead' => 'Project lead',
        'project.status' => 'Project status',
        'agency.name' => 'Agency name',
        'prepared_by' => 'Person generating the document',
        'today' => "Today's date",
        'tasks_table' => 'Table of the project\'s open tasks',
        'hours_table' => 'Table of hours by person this month (project)',
    ];

    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly CompanyProfile $company,
    ) {}

    /**
     * @return array{markdown: string, unknown: list<string>}
     */
    public function merge(string $body, Workspace $workspace, Project $project, User $by): array
    {
        $unknown = [];
        $markdown = preg_replace_callback('/\{\{\s*([a-z_.]+)\s*\}\}/', function ($m) use ($workspace, $project, $by, &$unknown) {
            $value = $this->value($m[1], $workspace, $project, $by);
            if ($value === null) {
                $unknown[] = $m[1];

                return '[unknown: '.$m[1].']';
            }

            return $value;
        }, $body) ?? $body;

        return ['markdown' => $markdown, 'unknown' => array_values(array_unique($unknown))];
    }

    /**
     * @return array{attachment_id: int, unknown: list<string>}
     */
    public function generate(DocumentTemplate $template, Workspace $workspace, Project $project, User $by): array
    {
        $merged = $this->merge($template->body, $workspace, $project, $by);
        $html = (new GithubFlavoredMarkdownConverter(['html_input' => 'escape', 'allow_unsafe_links' => false]))->convert($merged['markdown'])->getContent();
        $bytes = $this->pdf->render('pdf.document', ['title' => $template->name, 'html' => $html, 'workspace' => $workspace, 'project' => $project, 'version' => $template->version]);
        $file = $this->pdf->save($project, $workspace->id, $template->name.' - '.$project->name, $bytes, $by);
        $template->increment('uses');

        return ['attachment_id' => $file->id, 'unknown' => $merged['unknown']];
    }

    private function value(string $field, Workspace $workspace, Project $project, User $by): ?string
    {
        $date = fn ($d) => $d !== null ? $d->format('F j, Y') : '';

        return match ($field) {
            'client.name' => self::text($workspace->name),
            'client.key' => self::text((string) $workspace->key),
            'client.website' => self::text((string) $workspace->website),
            'client.contact' => self::text((string) $workspace->primary_contact_name),
            'project.name' => self::text($project->name),
            'project.description' => self::text((string) $project->description),
            'project.start_on' => $date($project->start_on),
            'project.due_on' => $date($project->due_on),
            'project.lead' => self::text((string) ($project->lead_user_id !== null ? User::query()->whereKey($project->lead_user_id)->value('name') : '')),
            'project.status' => self::text(ucfirst($project->status)),
            'agency.name' => self::text((string) $this->company->get()['name']),
            'prepared_by' => self::text($by->name),
            'today' => now()->format('F j, Y'),
            'tasks_table' => $this->tasksTable($workspace, $project),
            'hours_table' => $this->hoursTable($project),
            default => null,
        };
    }

    private function tasksTable(Workspace $workspace, Project $project): string
    {
        $done = TaskStatus::ordered()->where('category', TaskStatus::CATEGORY_DONE)->pluck('id')->all();
        $names = TaskStatus::ordered()->pluck('name', 'id');
        $rows = Task::query()->where('project_id', $project->id)->whereNotIn('status_id', $done)->orderBy('due_on')->limit(60)->get(['number', 'title', 'status_id', 'due_on']);
        if ($rows->isEmpty()) {
            return '_No open tasks._';
        }
        $lines = ['| Task | Title | Status | Due |', '|---|---|---|---|'];
        foreach ($rows as $t) {
            $lines[] = '| '.($workspace->key !== null && $t->number !== null ? $workspace->key.'-'.$t->number : '').' | '.self::cell($t->title).' | '.self::cell((string) ($names[$t->status_id] ?? '')).' | '.($t->due_on?->format('M j') ?? '').' |';
        }

        return implode("\n", $lines);
    }

    private function hoursTable(Project $project): string
    {
        $rows = TimeEntry::query()->join('tasks', 'tasks.id', '=', 'time_entries.task_id')->join('users', 'users.id', '=', 'time_entries.user_id')
            ->where('tasks.project_id', $project->id)
            ->whereBetween('time_entries.entry_date', [now()->startOfMonth()->toDateString(), now()->toDateString()])
            ->groupBy('users.name')->selectRaw('users.name as name, sum(time_entries.minutes) as m')->orderByDesc('m')->get();
        if ($rows->isEmpty()) {
            return '_No time logged this month._';
        }
        $lines = ['| Person | Hours |', '|---|---:|'];
        foreach ($rows as $r) {
            $m = (int) $r->getAttribute('m');
            $lines[] = '| '.self::cell((string) $r->getAttribute('name')).' | '.sprintf('%d:%02d', intdiv($m, 60), $m % 60).' |';
        }

        return implode("\n", $lines);
    }

    /** Merge values are text, not Markdown: neutralise characters Markdown would act on. */
    private static function text(string $value): string
    {
        return addcslashes(str_replace(["\r\n", "\r"], "\n", $value), '\\`*_[]<>#|');
    }

    private static function cell(string $value): string
    {
        return str_replace(["\n", '|'], [' ', '\|'], self::text($value));
    }
}
