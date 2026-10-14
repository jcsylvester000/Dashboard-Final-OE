<?php

namespace Database\Seeders;

use App\Domain\Billing\RateTemplateService;
use App\Domain\Files\AttachmentService;
use App\Domain\People\Capacity;
use App\Domain\Reports\TimeAggregates;
use App\Domain\Work\CommentService;
use App\Models\BillingProfile;
use App\Models\Department;
use App\Models\LeaveRequest;
use App\Models\Project;
use App\Models\RateCard;
use App\Models\RateTemplate;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;

/**
 * Realistic agency demo data in plain English, built around the three QA scenarios:
 *
 *  1. Development MVP build   - Brewhouse Coffee Co. "Online Ordering App - MVP 1" (sprints S1-S4).
 *  2. Marketing + SEO campaign - Brewhouse "Holiday 2026 Ads + Social Campaign" and local SEO retainer.
 *  3. Cross-department launch  - Desert Sun Realty "Arizona Market Launch" (Research -> Marketing -> Development).
 *  Plus a third client (Bright Smile Dental) so reports and billing have more than one account.
 *
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Safe to re-run: it first removes the previous demo clients and demo users (everything
 * under them cascades). Refuses to run in production. Demo users sign in with "password".
 */
class DemoDataSeeder extends Seeder
{
    /** Old faker-based demo clients, removed on re-run. */
    private const LEGACY_WORKSPACES = ['Demo Brewhouse', 'Demo Realty', 'Demo Dental'];

    /** dept slug => [name, title] (index 0 is the department lead). */
    private const PEOPLE = [
        'development' => [['Marco Reyes', 'Tech Lead'], ['Aisha Khan', 'Full-stack Developer'], ['Leo Bautista', 'Front-end Developer']],
        'research' => [['Priya Nair', 'Research Lead'], ['Daniel Cruz', 'Market Researcher']],
        'product' => [['Hannah Lee', 'Product Lead'], ['Sam Ortega', 'Product Designer (UX/UI)']],
        'marketing' => [['Jasmine Torres', 'Marketing Lead'], ['Nico Santos', 'Content & Social Specialist'], ['Bea Villanueva', 'Graphic Designer']],
        'seo' => [['Ethan Walker', 'SEO Lead'], ['Clara Mendoza', 'SEO Specialist']],
    ];

    /** Short time-entry notes per department. */
    private const NOTES = [
        'development' => ['Implementation', 'Code review fixes', 'Wrote tests', 'Debugging', 'Pairing session', 'Refactor and cleanup', 'Deployed to staging'],
        'research' => ['Desk research', 'Interview notes', 'Data pull and analysis', 'Wrote findings', 'Competitor review'],
        'product' => ['Wireframing', 'Figma iterations', 'Stakeholder review', 'Acceptance criteria', 'Usability session'],
        'marketing' => ['Drafting', 'Design iterations', 'Client feedback round', 'Scheduling and setup', 'Revisions'],
        'seo' => ['Audit', 'On-page updates', 'Keyword mapping', 'Outreach', 'Reporting'],
    ];

    /** @var array<string, list<User>> */
    private array $staff = [];

    /** @var array<string, int> */
    private array $dept = [];

    /** @var array<string, int> */
    private array $status = [];

    private CarbonImmutable $today;

    private Randomizer $rng;

    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoDataSeeder must never run in production.');
        }

        // Own generator: Faker (used by the user factory) shares PHP's global mt_rand, so a
        // global seed would drift between runs. This keeps the data identical every run.
        $this->rng = new Randomizer(new Mt19937(2026));
        $this->today = CarbonImmutable::today();
        $this->call([RolesAndPermissionsSeeder::class, DepartmentSeeder::class, HolidaySeeder::class]);

        DB::transaction(function () {
            $this->removePreviousDemo();
            $this->call(DemoFinanceSeeder::class);

            $this->dept = Department::query()->pluck('id', 'slug')->map(fn ($id) => (int) $id)->all();
            $this->status = TaskStatus::ordered()->pluck('id', 'slug')->map(fn ($id) => (int) $id)->all();

            $this->createStaff();
            $this->agencyRates();

            $this->brewhouse();
            $this->desertSun();
            $this->brightSmile();
            $this->rateTemplate();
            $this->leave();
        });

        $this->command->info('Demo data ready: 3 clients, '.Task::count().' tasks. Sign in as demo.<dept>.<n>@overeasy.local / password.');
    }

    // ------------------------------------------------------------------ setup

    private function removePreviousDemo(): void
    {
        // Every table below workspaces/users uses cascade or null-on-delete foreign keys.
        Workspace::withTrashed()
            ->where(fn ($q) => $q->whereIn('name', self::LEGACY_WORKSPACES)->orWhere('slug', 'like', 'demo-%'))
            ->get()
            ->each(fn (Workspace $w) => DB::table('workspaces')->where('id', $w->id)->delete());

        DB::table('users')->where('email', 'like', 'demo.%@overeasy.local')->delete();
    }

    private function createStaff(): void
    {
        foreach (self::PEOPLE as $slug => $people) {
            $deptId = $this->dept[$slug];
            foreach ($people as $i => [$name, $title]) {
                $user = User::factory()->withRole($i === 0 ? 'dept-lead' : 'member')->create([
                    'name' => $name,
                    'title' => $title,
                    'email' => "demo.{$slug}.".($i + 1).'@overeasy.local',
                    'primary_department_id' => $deptId,
                ]);
                $user->departments()->syncWithoutDetaching([$deptId]);
                if ($i === 0) {
                    Department::query()->whereKey($deptId)->update(['lead_user_id' => $user->id]);
                }
                $this->staff[$slug][] = $user;
            }
        }
    }

    private function agencyRates(): void
    {
        if (RateCard::query()->whereNull('workspace_id')->exists()) {
            return;
        }
        $from = $this->today->subYear()->startOfYear()->toDateString();
        RateCard::create(['workspace_id' => null, 'department_id' => null, 'user_id' => null, 'rate_minor' => 250000, 'cost_minor' => 90000, 'effective_from' => $from]);
        RateCard::create(['workspace_id' => null, 'department_id' => $this->dept['development'], 'user_id' => null, 'rate_minor' => 300000, 'cost_minor' => 110000, 'effective_from' => $from]);
        RateCard::create(['workspace_id' => null, 'department_id' => $this->dept['seo'], 'user_id' => null, 'rate_minor' => 220000, 'cost_minor' => 80000, 'effective_from' => $from]);
    }

    private function rand(int $min, int $max): int
    {
        return $this->rng->getInt($min, $max);
    }

    /**
     * Per-person rates as a template, applied to every demo client (each gets its own copy).
     */
    private function rateTemplate(): void
    {
        $templates = app(RateTemplateService::class);
        $template = RateTemplate::query()->firstOrCreate(['name' => 'Standard agency rates 2026'], ['description' => 'Per-person client rates (demo)']);
        $items = [];
        foreach ($this->staff as $slug => $people) {
            foreach ($people as $i => $u) {
                $rate = match (true) {
                    $i === 0 => 3500,
                    $slug === 'development' => 3000,
                    $u->title === 'Graphic Designer' => 2200,
                    default => 2500,
                };
                $items[] = ['user_id' => $u->id, 'rate' => (string) $rate, 'cost' => (string) round($rate * 0.38)];
            }
        }
        $items[] = ['rate' => '2500', 'cost' => '900'];
        $templates->saveItems($template, $items);

        $admin = $this->person('product', 1);
        $from = $this->today->subYear()->startOfYear()->toDateString();
        Workspace::query()->where('slug', 'like', 'demo-%')->get()
            ->each(fn (Workspace $ws) => $templates->apply($template, $ws, $from, $admin));
    }

    private function person(string $slug, int $n): User
    {
        return $this->staff[$slug][$n - 1];
    }

    /**
     * @param  array{name: string, slug: string, industry: string, website: string, color: string, contact: string, description: string, series: string, bill_to: string, lead: User}  $c
     */
    private function client(array $c): Workspace
    {
        // Readable task keys for the demo (BRW-24), unless a real client already uses them.
        $key = ['demo-brewhouse-coffee' => 'BRW', 'demo-desert-sun-realty' => 'DSR', 'demo-bright-smile-dental' => 'BSD'][$c['slug']] ?? null;
        $ws = Workspace::create([
            'key' => $key !== null && ! Workspace::query()->withTrashed()->where('key', $key)->exists() ? $key : null,
            'name' => $c['name'],
            'slug' => $c['slug'],
            'industry' => $c['industry'],
            'website' => $c['website'],
            'status' => 'active',
            'color' => $c['color'],
            'description' => $c['description'],
            'primary_contact_name' => $c['contact'],
        ]);

        foreach ($this->staff as $slug => $people) {
            foreach ($people as $user) {
                $ws->members()->attach($user->id, [
                    'role' => $user->is($c['lead']) ? Workspace::ROLE_LEAD : Workspace::ROLE_MEMBER,
                    'department_id' => $this->dept[$slug],
                ]);
            }
        }

        BillingProfile::create([
            'workspace_id' => $ws->id,
            'currency' => 'PHP',
            'cycle' => 'monthly',
            'retainer_minor' => 0,
            'included_minutes' => 0,
            'overage_rule' => 'bill',
            'terms_days' => 15,
            'tax_rate_bp' => 1200,
            'invoice_series' => $c['series'],
            'bill_to' => ['name' => $c['bill_to'], 'contact' => $c['contact']],
        ]);

        return $ws;
    }

    /**
     * @param  list<User>  $team
     */
    private function project(Workspace $ws, string $name, string $type, User $lead, int $startOffset, int $dueOffset, string $brief, array $team): Project
    {
        $project = Project::create([
            'workspace_id' => $ws->id,
            'name' => $name,
            'type' => $type,
            'status' => 'active',
            'lead_user_id' => $lead->id,
            'description' => $brief,
            'start_on' => $this->today->addDays($startOffset)->toDateString(),
            'due_on' => $this->today->addDays($dueOffset)->toDateString(),
            'created_by' => $lead->id,
        ]);
        $project->members()->sync(collect([$lead, ...$team])->pluck('id')->unique()->values()->all());

        return $project;
    }

    /**
     * HRIS scenario H2 (B-14): Clara has 2 days approved next week, Nico has a pending day,
     * Aisha's leave spans All Souls' Day (Nov 2, a holiday) so only 1 working day counts.
     */
    private function leave(): void
    {
        $monday = $this->today->next(CarbonImmutable::MONDAY);
        $lead = $this->person('seo', 1);
        /** @var list<array{0: User, 1: string, 2: CarbonImmutable, 3: CarbonImmutable, 4: string, 5: string, 6: User|null}> $plan */
        $plan = [
            [$this->person('seo', 2), 'vacation', $monday, $monday->addDay(), 'Family trip to Baguio', LeaveRequest::APPROVED, $lead],
            [$this->person('marketing', 2), 'vacation', $monday->addDays(4), $monday->addDays(4), 'Wedding of a friend', LeaveRequest::PENDING, null],
            [$this->person('development', 2), 'vacation', CarbonImmutable::parse($this->today->format('Y').'-11-02'), CarbonImmutable::parse($this->today->format('Y').'-11-03'), 'Undas with family', LeaveRequest::APPROVED, $this->person('development', 1)],
        ];
        $capacity = app(Capacity::class);
        foreach ($plan as [$user, $type, $from, $to, $reason, $status, $by]) {
            $days = count($capacity->load($from->toDateString(), $to->toDateString())->workingDays($from->toDateString(), $to->toDateString()));
            $leave = LeaveRequest::create(['user_id' => $user->id, 'type' => $type, 'starts_on' => $from->toDateString(), 'ends_on' => $to->toDateString(), 'working_days' => max(1, $days), 'reason' => $reason]);
            $leave->forceFill(['status' => $status, 'reviewed_by' => $by?->id, 'reviewed_at' => $by ? $this->today : null])->save();
        }
    }

    /**
     * Jira-style sprints for the MVP (J1): S1-S2 closed, S3 active with a burndown, S4 planned.
     * Tasks are matched by their "S1 ·" title prefix; points come from the estimate.
     */
    private function sprints(Workspace $ws, Project $mvp, User $lead, Task $bug): void
    {
        /** @var array<string, array{0: int, 1: int, 2: string, 3: string}> $plan */
        $plan = [
            'S1' => [-35, -26, Sprint::CLOSED, 'Scope, wireframes and catalog API'],
            'S2' => [-25, -15, Sprint::CLOSED, 'Accounts, menu pages and store locator'],
            'S3' => [-14, 4, Sprint::ACTIVE, 'Customers can pay with GCash, Maya or card'],
            'S4' => [5, 18, Sprint::PLANNED, 'Loyalty, performance and launch readiness'],
        ];
        $points = fn (int $minutes): int => match (true) {
            $minutes <= 240 => 2,
            $minutes <= 480 => 3,
            $minutes <= 720 => 5,
            $minutes <= 960 => 8,
            default => 13,
        };

        foreach ($plan as $prefix => [$start, $end, $status, $goal]) {
            $sprint = Sprint::create([
                'workspace_id' => $ws->id,
                'project_id' => $mvp->id,
                'name' => 'Sprint '.substr($prefix, 1),
                'goal' => $goal,
                'starts_on' => $this->today->addDays($start)->toDateString(),
                'ends_on' => $this->today->addDays($end)->toDateString(),
                'created_by' => $lead->id,
            ]);

            $tasks = Task::query()->where('project_id', $mvp->id)->where('title', 'like', $prefix.' ·%')->get();
            if ($prefix === 'S3') {
                $tasks->push($bug);
            }
            foreach ($tasks as $task) {
                $task->forceFill(['sprint_id' => $sprint->id, 'story_points' => $points((int) $task->estimate_minutes)])->saveQuietly();
            }

            $committed = (int) $tasks->sum('story_points');
            $sprint->forceFill([
                'status' => $status,
                'started_at' => $status === Sprint::PLANNED ? null : $this->today->addDays($start)->setTime(9, 0),
                'closed_at' => $status === Sprint::CLOSED ? $this->today->addDays($end)->setTime(17, 0) : null,
                'committed_points' => $status === Sprint::PLANNED ? null : $committed,
                'completed_points' => $status === Sprint::CLOSED ? (int) $tasks->whereNotNull('completed_at')->sum('story_points') : null,
                'carried_over' => $status === Sprint::CLOSED ? $tasks->whereNull('completed_at')->count() : null,
            ])->save();
        }
    }

    /**
     * A zero-length milestone (task type 'milestone') due $due days from today.
     */
    private function milestone(Workspace $ws, Project $project, string $title, string $dept, int $due): Task
    {
        $task = $this->task($ws, $project, $title, $dept, 1, 0, 'todo', $due, $due, 'high', null, false);
        $task->forceFill(['type' => 'milestone'])->saveQuietly();

        return $task;
    }

    /**
     * One task with realistic time history.
     *
     * @param  array<string, string>|null  $details
     */
    private function task(Workspace $ws, ?Project $project, string $title, string $dept, int $who, float $hours, string $status, int $start, int $due, string $priority = 'normal', ?array $details = null, bool $billable = true, ?string $description = null, ?Task $handoffFrom = null): Task
    {
        $assignee = $this->person($dept, $who);
        $reporter = $project !== null && $project->lead_user_id !== null ? (User::query()->find($project->lead_user_id) ?? $assignee) : $assignee;
        $done = $status === 'done';
        $created = $this->today->addDays(min($start, 0))->setTime(9, $this->rand(0, 59));

        $task = Task::create([
            'workspace_id' => $ws->id,
            'project_id' => $project?->id,
            'department_id' => $this->dept[$dept],
            'status_id' => $this->status[$status],
            'handoff_from_task_id' => $handoffFrom?->id,
            'title' => $title,
            'type' => match (true) {
                str_starts_with($title, 'Bug:') => 'bug',
                (bool) preg_match('/^S\d+[ :]/', $title) => 'story',
                default => 'task',
            },
            'description' => $description,
            'work_details' => $details,
            'priority' => $priority,
            'assignee_id' => $assignee->id,
            'reporter_id' => $reporter->id,
            'due_on' => $this->today->addDays($due)->toDateString(),
            // Bars on the calendar and timeline run from start to due (S1-17, B-02).
            'start_on' => $this->today->addDays(min($start, $due))->toDateString(),
            'estimate_minutes' => (int) round($hours * 60),
            'position' => Task::query()->where('workspace_id', $ws->id)->where('status_id', $this->status[$status])->count(),
        ]);
        $task->forceFill([
            'created_at' => $created,
            'updated_at' => $created,
            'completed_at' => $done ? $this->today->addDays(min($due, 0))->setTime(17, 0) : null,
        ])->saveQuietly();
        $task->watchers()->syncWithoutDetaching(array_values(array_unique([$assignee->id, $reporter->id])));

        DB::table('activity_log')->insert([
            'actor_id' => $reporter->id,
            'action' => 'task.created',
            'subject_type' => 'task',
            'subject_id' => $task->id,
            'properties' => json_encode(['workspace_id' => $ws->id]),
            'created_at' => $created,
        ]);

        $this->logTime($ws, $task, $assignee, $dept, $hours, $status, $start, $due, $billable);

        return $task;
    }

    private function logTime(Workspace $ws, Task $task, User $who, string $dept, float $hours, string $status, int $start, int $due, bool $billable): void
    {
        $share = match ($status) {
            'done' => $this->rand(85, 115) / 100,
            'in-review' => $this->rand(80, 100) / 100,
            'in-progress', 'blocked' => $this->rand(30, 70) / 100,
            default => 0,
        };
        $remaining = (int) round($hours * 60 * $share / 15) * 15;
        if ($remaining <= 0) {
            return;
        }

        $first = $this->today->addDays(min($start, 0));
        $last = $status === 'done' ? $this->today->addDays(min($due, 0)) : $this->today;
        $days = [];
        for ($d = $first; $d->lte($last); $d = $d->addDay()) {
            if (! $d->isWeekend()) {
                $days[] = $d;
            }
        }
        if ($days === []) {
            $days = [$last];
        }

        $weekStart = $this->today->startOfWeek();
        /** @var User|null $lead */
        $lead = $ws->members()->wherePivot('role', Workspace::ROLE_LEAD)->first();
        $approver = collect([$this->staff[$dept][0], $lead])->filter()->first(fn (User $u) => ! $u->is($who));
        $notes = self::NOTES[$dept];
        $rows = [];

        while ($remaining > 0) {
            $minutes = min($remaining, [30, 45, 60, 90, 120, 150, 180, 240][$this->rand(0, 7)]);
            $remaining -= $minutes;
            $day = $days[$this->rand(0, count($days) - 1)];
            $approved = $day->lt($weekStart) && $approver !== null;

            $rows[] = [
                'user_id' => $who->id,
                'workspace_id' => $ws->id,
                'task_id' => $task->id,
                'project_id' => $task->project_id,
                'department_id' => $task->department_id,
                'entry_date' => $day->toDateString(),
                'minutes' => $minutes,
                'note' => $notes[$this->rand(0, count($notes) - 1)],
                'is_billable' => $billable,
                'approved_by' => $approved ? $approver->id : null,
                'approved_at' => $approved ? $day->addDays($this->rand(1, 4))->setTime(10, 0) : null,
                'created_at' => $day->setTime(18, 0),
                'updated_at' => $day->setTime(18, 0),
            ];
        }

        DB::table('time_entries')->insert($rows);
        // E-129: bulk inserts skip model events, so rebuild the daily totals reports read.
        app(TimeAggregates::class)->rebuild();
    }

    private function waitsOn(Task $task, Task $on): void
    {
        $task->dependencies()->syncWithoutDetaching([$on->id => ['type' => 'blocks', 'created_by' => $task->reporter_id]]);
    }

    private function comment(Task $task, User $by, string $body, int $daysAgo): void
    {
        $at = $this->today->subDays($daysAgo)->setTime($this->rand(9, 17), $this->rand(0, 59));
        // Through the comment service so @mentions reach the Inbox like real comments.
        $c = app(CommentService::class)->create($task, $body, $by);
        $c->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
    }

    /**
     * A small real file on a task (stored like any upload), optionally approved.
     */
    private function sampleFile(Task $task, User $by, string $name, string $mime, string $bytes, ?User $approvedBy = null): void
    {
        $path = tempnam(sys_get_temp_dir(), 'demo');
        file_put_contents((string) $path, $bytes);
        $attachment = app(AttachmentService::class)->attach($task, $task->workspace_id, new UploadedFile((string) $path, $name, $mime, null, true), $by);
        if ($approvedBy !== null) {
            $attachment->forceFill(['approved_at' => now()->subDays(1), 'approved_by' => $approvedBy->id])->save();
        }
        @unlink((string) $path);
    }

    /** Solid-colour PNG without GD (so it works on any PHP install). */
    private static function png(int $w, int $h, int $r, int $g, int $b): string
    {
        $row = "\0".str_repeat(chr($r).chr($g).chr($b), $w);
        $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

        return "\x89PNG\r\n\x1a\n"
            .$chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0))
            .$chunk('IDAT', (string) gzcompress(str_repeat($row, $h)))
            .$chunk('IEND', '');
    }

    /** Minimal one-page PDF with a title line. */
    private static function pdf(string $text): string
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 18 Tf 72 720 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $obj) {
            $offsets[] = strlen($out);
            $out .= ($i + 1)." 0 obj\n{$obj}\nendobj\n";
        }
        $xref = strlen($out);
        $out .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }

        return $out.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%EOF";
    }

    private function tag(User $u): string
    {
        return '@['.$u->name.'](user:'.$u->id.')';
    }

    // ---------------------------------------------------------------- clients

    /** Scenario 1 (Dev MVP) + Scenario 2 (Marketing & SEO campaign). */
    private function brewhouse(): void
    {
        $marco = $this->person('development', 1);
        $ws = $this->client([
            'name' => 'Brewhouse Coffee Co.', 'slug' => 'demo-brewhouse-coffee', 'industry' => 'Hospitality',
            'website' => 'https://brewhousecoffee.example', 'color' => 'amber', 'contact' => 'Carla Dizon (Owner)',
            'description' => 'Specialty coffee chain, 6 branches in Metro Manila. Building an online ordering app; monthly SEO and seasonal campaigns.',
            'series' => 'BRW', 'bill_to' => 'Brewhouse Coffee Co., Inc.', 'lead' => $marco,
        ]);

        // --- Scenario 1: MVP build, tracked in weekly sprints.
        $mvp = $this->project($ws, 'Online Ordering App – MVP 1', 'project', $marco, -35, 21,
            "Goal: customers order ahead and pay online (GCash, Maya, cards) for pick-up at any of the 6 branches.\nMVP 1 = menu, accounts, cart, checkout, order status, barista order queue.\nSprints are one week; tasks are prefixed S1–S4. Launch target is the due date.",
            [$this->person('development', 2), $this->person('development', 3), $this->person('product', 1), $this->person('product', 2), $this->person('research', 2)]);

        $this->task($ws, $mvp, 'S1 · MVP scope and user stories sign-off', 'product', 1, 6, 'done', -35, -31, 'high');
        $this->task($ws, $mvp, 'S1 · Competitor teardown: Starbucks PH, Tim Hortons, Coffee Project apps', 'research', 2, 8, 'done', -35, -30);
        $this->task($ws, $mvp, 'S1 · Wireframes: menu, cart, checkout', 'product', 2, 12, 'done', -33, -28);
        $this->task($ws, $mvp, 'S1 · Project setup: Laravel + Inertia, CI, staging on Forge', 'development', 1, 8, 'done', -34, -30);
        $catalog = $this->task($ws, $mvp, 'S1 · Menu and product catalog API', 'development', 2, 14, 'done', -31, -26);
        $this->task($ws, $mvp, 'S2 · High-fidelity UI in Figma (mobile first)', 'product', 2, 16, 'done', -28, -21);
        $this->task($ws, $mvp, 'S2 · Customer accounts: sign-up, login, SMS OTP', 'development', 2, 12, 'done', -26, -20);
        $this->task($ws, $mvp, 'S2 · Menu and product pages (server-rendered, optimised images)', 'development', 3, 14, 'done', -24, -19);
        $this->task($ws, $mvp, 'S2 · Store locator with branch hours', 'development', 3, 6, 'done', -22, -18);
        $cart = $this->task($ws, $mvp, 'S3 · Cart and checkout flow', 'development', 3, 16, 'in-progress', -14, 2, 'high');
        $pay = $this->task($ws, $mvp, 'S3 · PayMongo payments: GCash, Maya, cards (webhooks + idempotency)', 'development', 2, 18, 'in-progress', -12, 4, 'urgent',
            null, true, 'Use PayMongo payment intents. Verify webhook signatures and store provider event IDs (unique) so retries never double-charge.');
        $this->task($ws, $mvp, 'S3 · Barista order queue (admin)', 'development', 1, 12, 'in-progress', -8, 5, 'high');
        $this->task($ws, $mvp, 'S3 · Usability test: script + 5 customer sessions', 'product', 1, 8, 'in-review', -7, 1);
        $this->task($ws, $mvp, 'S3 · Launch promo and pricing benchmark', 'research', 1, 6, 'done', -10, -5);
        $bug = $this->task($ws, $mvp, 'Bug: duplicate order when "Pay" is tapped twice', 'development', 2, 3, 'blocked', -3, 1, 'urgent',
            null, true, 'Seen on staging with slow 3G. The second tap creates a second payment intent.');
        $orderStatus = $this->task($ws, $mvp, 'S3 · Order status page + SMS notifications', 'development', 2, 10, 'todo', -2, 6);
        $this->task($ws, $mvp, 'S4 · Loyalty points: earn and redeem', 'development', 3, 14, 'backlog', 3, 14);
        $this->task($ws, $mvp, 'S4 · Performance pass: LCP under 2.5s on 4G', 'development', 1, 8, 'backlog', 7, 16);
        $this->task($ws, $mvp, 'S4 · Security review and fixes', 'development', 1, 10, 'backlog', 9, 18, 'high');
        $this->task($ws, $mvp, 'S4 · Launch checklist and go-live plan', 'product', 1, 4, 'todo', 5, 19);
        $this->task($ws, $mvp, 'Sprint planning and demos (internal)', 'development', 1, 6, 'in-progress', -35, 21, 'low', null, false);

        $this->waitsOn($cart, $catalog);
        $this->waitsOn($orderStatus, $pay);
        $this->waitsOn($bug, $pay);
        $this->sprints($ws, $mvp, $marco, $bug);
        $this->comment($pay, $this->person('development', 2), 'Webhook signature check is in. Using the PayMongo event ID as the idempotency key so retries don\'t double-charge.', 3);
        $this->comment($pay, $marco, $this->tag($this->person('development', 2)).' nice. Please add a test for the double-tap bug before we close this one.', 2);
        $this->comment($cart, $this->person('product', 2), 'Updated the checkout screen in Figma: the pick-up time selector moved above payment.', 4);

        // --- Scenario 2: Holiday campaign + SEO.
        $jas = $this->person('marketing', 1);
        $holiday = $this->project($ws, 'Holiday 2026 Ads + Social Campaign', 'campaign', $jas, -21, 45,
            "Facebook + Instagram ads, TikTok organic and a holiday landing page.\nGoal: +25% gift card sales vs last year. Budget ₱120,000 ad spend.\nAll creatives need client approval before launch on Nov 1.",
            [$this->person('marketing', 2), $this->person('marketing', 3), $this->person('seo', 1), $this->person('seo', 2)]);
        // Creatives need the client lead's sign-off before they count as Done (S2-13).
        $holiday->forceFill(['review_required' => true])->saveQuietly();
        $m = fn (string $channel, string $type) => ['channel' => $channel, 'campaign' => 'Holiday 2026', 'deliverable_type' => $type];
        // Campaign milestones (S2-09): client approval, then launch on Nov 1.
        $this->milestone($ws, $holiday, 'Client approves all creatives', 'marketing', 14);
        $launch = (int) round($this->today->diffInDays(CarbonImmutable::parse($this->today->format('Y').'-11-01'), false));
        $this->milestone($ws, $holiday, 'Launch: holiday campaign live', 'marketing', $launch > 0 ? $launch : 24);

        $this->task($ws, $holiday, 'Campaign brief and content calendar', 'marketing', 1, 6, 'done', -21, -16, 'high', $m('Other', 'Report'));
        $this->task($ws, $holiday, 'Ad copy: 3 hooks x 2 audiences', 'marketing', 2, 4, 'done', -9, -5, 'normal', $m('Facebook', 'Copy'));
        $creatives = $this->task($ws, $holiday, 'FB/IG ad creatives (1080x1080, 1080x1920, 1200x628)', 'marketing', 3, 12, 'in-review', -10, 3, 'high', $m('Facebook', 'Ad creative'));
        $this->task($ws, $holiday, 'TikTok: "Holiday drinks in 15 seconds" video', 'marketing', 2, 8, 'in-progress', -6, 5, 'normal', $m('TikTok', 'Video'));
        $this->task($ws, $holiday, 'Instagram carousel: gift card bundles', 'marketing', 3, 5, 'todo', -1, 8, 'normal', $m('Instagram', 'Post'));
        $this->task($ws, $holiday, 'Meta Ads setup: audiences, pixel events, budget split', 'marketing', 1, 5, 'todo', 3, 12, 'normal', $m('Facebook', 'Other'));
        $this->task($ws, $holiday, 'Email: holiday menu launch', 'marketing', 2, 4, 'todo', 2, 12, 'normal', $m('Email', 'Copy'));
        $landing = $this->task($ws, $holiday, 'Holiday landing page: on-page SEO + schema', 'seo', 1, 6, 'backlog', 3, 14, 'normal',
            ['target_url' => 'https://brewhousecoffee.example/holiday', 'keyword' => 'holiday coffee gifts', 'work_type' => 'On-page'], true, null, $creatives);
        $this->task($ws, $holiday, 'UTM tagging plan for all holiday links', 'seo', 2, 2, 'todo', 0, 7, 'normal',
            ['target_url' => 'https://brewhousecoffee.example/holiday', 'keyword' => 'holiday coffee gifts', 'work_type' => 'Reporting']);
        $this->task($ws, $holiday, 'Campaign results report – week 1', 'marketing', 1, 3, 'backlog', 20, 28, 'normal', $m('Other', 'Report'));

        $this->sampleFile($creatives, $this->person('marketing', 3), 'Holiday ad 1080x1080 v2.png', 'image/png', self::png(540, 540, 170, 30, 40));
        $this->sampleFile($creatives, $this->person('marketing', 3), 'Holiday story 1080x1920 v2.png', 'image/png', self::png(270, 480, 170, 30, 40));
        $this->waitsOn($landing, $creatives);
        $this->comment($creatives, $this->person('marketing', 3), 'Uploaded v2 of all three sizes. The logo is 20% bigger per Carla\'s feedback.', 2);
        $this->comment($creatives, $jas, $this->tag($this->person('seo', 1)).' heads-up: the landing page work starts as soon as these are approved.', 1);

        $seo = $this->project($ws, 'Local SEO – Monthly Retainer', 'seo', $this->person('seo', 1), -90, 90,
            'Rank all 6 branches in the local pack for "coffee shop near me" and branch-area keywords. Monthly report by the 5th.',
            [$this->person('seo', 2)]);
        $s = fn (string $url, string $kw, string $type) => ['target_url' => $url, 'keyword' => $kw, 'work_type' => $type];
        $this->task($ws, $seo, 'Google Business Profile optimisation – 6 branches', 'seo', 2, 6, 'done', -30, -20, 'normal', $s('https://brewhousecoffee.example/locations', 'coffee shop near me', 'Local'));
        $this->task($ws, $seo, 'Monthly SEO report – September', 'seo', 1, 3, 'done', -6, -1, 'normal', $s('https://brewhousecoffee.example', 'brewhouse coffee', 'Reporting'));
        $this->task($ws, $seo, 'Fix 37 broken internal links (site audit)', 'seo', 2, 4, 'in-progress', -4, 3, 'normal', $s('https://brewhousecoffee.example', 'brewhouse coffee', 'Technical'));
        $this->task($ws, $seo, 'Blog: best coffee for working from home', 'seo', 2, 5, 'in-review', -8, 1, 'normal', $s('https://brewhousecoffee.example/blog/work-from-home-coffee', 'best coffee for work from home', 'Content'));
        $this->task($ws, $seo, 'Local citations: 20 directories', 'seo', 2, 5, 'todo', 1, 10, 'low', $s('https://brewhousecoffee.example/locations', 'coffee shop makati', 'Links'));
        $this->task($ws, null, 'Weekly client status call', 'development', 1, 4, 'in-progress', -28, 28);
    }

    /** Scenario 3: Research -> Marketing -> Development handoff chain. */
    private function desertSun(): void
    {
        $jas = $this->person('marketing', 1);
        $ws = $this->client([
            'name' => 'Desert Sun Realty', 'slug' => 'demo-desert-sun-realty', 'industry' => 'Real estate',
            'website' => 'https://desertsunrealty.example', 'color' => 'violet', 'contact' => 'Mike Alvarez (Broker)',
            'description' => 'Residential brokerage expanding into Arizona (Phoenix, Scottsdale, Tucson). Website care plan + launch campaign.',
            'series' => 'DSR', 'bill_to' => 'Desert Sun Realty LLC', 'lead' => $jas,
        ]);

        $az = $this->project($ws, 'Arizona Market Launch Campaign', 'campaign', $jas, -21, 40,
            "Launch in Phoenix, Scottsdale and Tucson.\n1) Research: market + keyword brief.\n2) Marketing: approved ad creatives and landing copy.\n3) Development: integrate the approved assets into /arizona with a lead form wired to the CRM.\nSuccess = 60 qualified leads in the first 30 days.",
            [$this->person('research', 1), $this->person('research', 2), $this->person('marketing', 2), $this->person('marketing', 3), $this->person('development', 2), $this->person('development', 3), $this->person('seo', 1), $this->person('product', 2)]);
        $m = fn (string $channel, string $type) => ['channel' => $channel, 'campaign' => 'Arizona launch', 'deliverable_type' => $type];

        $brief = $this->task($ws, $az, 'AZ market and keyword research brief (Phoenix, Scottsdale, Tucson)', 'research', 2, 10, 'done', -20, -14, 'high',
            null, true, 'Top terms: "homes for sale scottsdale", "phoenix realtor", "tucson real estate agent". Median prices and buyer profiles per city are in the attached sheet.');
        $this->task($ws, $az, 'Competitor analysis: top 5 Arizona brokerages', 'research', 1, 6, 'done', -19, -13);
        $ads = $this->task($ws, $az, 'AZ ad creatives and landing page copy', 'marketing', 3, 12, 'done', -13, -6, 'high', $m('Facebook', 'Ad creative'), true,
            'Approved by Mike on the review call. Final files are attached to this task.', $brief);
        $this->task($ws, $az, 'Google Ads search campaign build (3 city ad groups)', 'marketing', 1, 6, 'in-progress', -5, 4, 'normal', $m('Google Ads', 'Other'));
        $integrate = $this->task($ws, $az, 'Integrate approved AZ assets: /arizona landing page + lead form', 'development', 2, 14, 'in-progress', -5, 6, 'high',
            null, true, 'Use the approved creatives from the Marketing task. Lead form: name, email, phone, city, budget range, consent checkbox.', $ads);
        $crm = $this->task($ws, $az, 'Lead form → CRM webhook (Follow Up Boss)', 'development', 3, 6, 'todo', 0, 8);
        $cities = $this->task($ws, $az, 'City pages: Phoenix, Scottsdale, Tucson (on-page SEO)', 'seo', 1, 9, 'backlog', 1, 12, 'normal',
            ['target_url' => 'https://desertsunrealty.example/arizona/scottsdale', 'keyword' => 'homes for sale scottsdale', 'work_type' => 'On-page']);
        $this->task($ws, $az, 'Landing page QA on mobile + accessibility check', 'product', 2, 4, 'backlog', 5, 10);
        $this->task($ws, $az, 'Launch email to past clients', 'marketing', 2, 3, 'backlog', 6, 12, 'normal', $m('Email', 'Copy'));
        $this->task($ws, $az, '30-day lead quality review', 'research', 1, 4, 'backlog', 30, 35, 'low');

        $this->sampleFile($brief, $this->person('research', 2), 'AZ market and keyword brief.pdf', 'application/pdf', self::pdf('Arizona market + keyword brief - Phoenix, Scottsdale, Tucson'));
        $this->sampleFile($ads, $this->person('marketing', 3), 'AZ ad 1080x1080 v3.png', 'image/png', self::png(540, 540, 196, 120, 60), $jas);
        $this->sampleFile($ads, $this->person('marketing', 3), 'AZ ad 1080x1920 v3.png', 'image/png', self::png(270, 480, 196, 120, 60), $jas);
        $this->sampleFile($ads, $this->person('marketing', 2), 'AZ landing page copy v2.pdf', 'application/pdf', self::pdf('Arizona landing page copy v2 (approved)'), $jas);
        $this->waitsOn($ads, $brief);
        $this->waitsOn($integrate, $ads);
        $this->waitsOn($crm, $integrate);
        $this->waitsOn($cities, $integrate);
        $this->comment($ads, $jas, 'Client approved v3 on today\'s call. '.$this->tag($this->person('development', 2)).' the files are attached here, you\'re clear to integrate.', 6);
        $this->comment($integrate, $this->person('development', 2), 'The landing page is on staging. Need the Tucson office address for the form footer.', 1);

        $care = $this->project($ws, 'Website Care Plan – Q4', 'project', $this->person('development', 1), -6, 85,
            'Monthly updates, uptime monitoring, backups and small fixes (up to 8 hours a month).', [$this->person('development', 1)]);
        $this->task($ws, $care, 'WordPress core and plugin updates – October', 'development', 1, 2, 'done', -6, -2);
        $this->task($ws, $care, 'Uptime alert: listing search timeout', 'development', 1, 3, 'done', -12, -11, 'urgent');
        $this->task($ws, $care, 'Monthly SEO report – September', 'seo', 1, 3, 'done', -6, -1, 'normal',
            ['target_url' => 'https://desertsunrealty.example', 'keyword' => 'phoenix realtor', 'work_type' => 'Reporting']);
    }

    private function brightSmile(): void
    {
        $hannah = $this->person('product', 1);
        $ws = $this->client([
            'name' => 'Bright Smile Dental', 'slug' => 'demo-bright-smile-dental', 'industry' => 'Healthcare',
            'website' => 'https://brightsmiledental.example', 'color' => 'emerald', 'contact' => 'Dr. Anna Lim',
            'description' => 'Two dental clinics in Makati and BGC. Online booking, Google Ads for new patients, local SEO.',
            'series' => 'BSD', 'bill_to' => 'Bright Smile Dental Clinic', 'lead' => $hannah,
        ]);

        $booking = $this->project($ws, 'Patient Booking Page Redesign', 'project', $hannah, -18, 14,
            'Three-step mobile booking (service, time, details) using the clinic\'s Cliniko calendar.', [$this->person('product', 2), $this->person('development', 3)]);
        $this->task($ws, $booking, 'Booking flow wireframes (3 steps, mobile)', 'product', 2, 6, 'done', -18, -12);
        $this->task($ws, $booking, 'Online booking widget integration (Cliniko)', 'development', 3, 10, 'in-progress', -6, 6, 'high');
        $this->task($ws, $booking, 'Patient photo consent form template', 'marketing', 1, 2, 'done', -15, -12);

        $ads = $this->project($ws, 'Google Ads – New Patient Specials', 'campaign', $this->person('marketing', 1), -10, 50,
            'Search ads for whitening and implant consultations. Target cost per booking under ₱600.', [$this->person('marketing', 2)]);
        $m = fn (string $channel, string $type) => ['channel' => $channel, 'campaign' => 'New patient specials', 'deliverable_type' => $type];
        $this->task($ws, $ads, 'Search ads: "dentist makati" and "teeth whitening"', 'marketing', 1, 5, 'in-review', -4, 2, 'normal', $m('Google Ads', 'Ad creative'));
        $this->task($ws, $ads, 'Promo landing page copy', 'marketing', 2, 3, 'done', -8, -5, 'normal', $m('Website', 'Landing page'));

        $seo = $this->project($ws, 'Local SEO – Makati and BGC Clinics', 'seo', $this->person('seo', 1), -40, 80,
            'Service pages and reviews to win local searches for implants, braces and whitening.', [$this->person('seo', 2)]);
        $this->task($ws, $seo, 'Service pages: implants, braces, whitening', 'seo', 2, 8, 'in-progress', -7, 7, 'normal',
            ['target_url' => 'https://brightsmiledental.example/services/implants', 'keyword' => 'dental implants makati', 'work_type' => 'Content']);
        $this->task($ws, $seo, 'Google review request process', 'seo', 1, 3, 'todo', 2, 9, 'low',
            ['target_url' => 'https://brightsmiledental.example', 'keyword' => 'dentist bgc', 'work_type' => 'Local']);
    }
}
