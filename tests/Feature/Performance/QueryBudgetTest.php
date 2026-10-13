<?php

namespace Tests\Feature\Performance;

use App\Models\BillingProfile;
use App\Models\Comment;
use App\Models\Label;
use App\Models\Project;
use App\Models\RateCard;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * E-118: query budgets for the busiest pages. Each page is loaded with 30 tasks (labels,
 * comments, time) so an N+1 query shows up as a blown budget, not a slow page in production.
 * Budgets are a little above today's counts (baseline 2026-10-14); raise one only with a reason.
 */
class QueryBudgetTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $lead;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->travelTo('2026-10-14 09:00:00');

        $this->ws = Workspace::factory()->create();
        $this->lead = User::factory()->withRole('super-admin')->create();
        $members = User::factory()->count(5)->withRole('member')->create();
        $this->ws->members()->attach($this->lead->id, ['role' => Workspace::ROLE_LEAD]);
        foreach ($members as $m) {
            $this->ws->members()->attach($m->id, ['role' => Workspace::ROLE_MEMBER]);
        }
        $project = Project::factory()->create(['workspace_id' => $this->ws->id]);
        $label = Label::create(['workspace_id' => $this->ws->id, 'name' => 'Urgent', 'color' => 'rose']);
        BillingProfile::create(['workspace_id' => $this->ws->id, 'tax_rate_bp' => 1200]);
        RateCard::create(['rate_minor' => 150000, 'effective_from' => '2026-01-01']);

        $tasks = Task::factory()->count(30)->create(['workspace_id' => $this->ws->id, 'project_id' => $project->id, 'assignee_id' => $members[0]->id, 'due_on' => '2026-10-20']);
        foreach ($tasks as $i => $t) {
            $t->labels()->attach($label->id);
            Comment::create(['workspace_id' => $this->ws->id, 'commentable_type' => $t->getMorphClass(), 'commentable_id' => $t->id, 'user_id' => $this->lead->id, 'body' => 'Note '.$i]);
            TimeEntry::create(['user_id' => $members[$i % 5]->id, 'workspace_id' => $this->ws->id, 'task_id' => $t->id, 'project_id' => $project->id, 'entry_date' => '2026-10-13', 'minutes' => 30, 'is_billable' => true]);
        }
        $this->task = $tasks[0];
    }

    private function queriesFor(string $url): int
    {
        $count = 0;
        DB::listen(function () use (&$count) {
            $count++;
        });
        $this->actingAs($this->lead)->get($url)->assertOk();

        return $count;
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function pages(): array
    {
        return [
            'dashboard' => ['dashboard', 30],
            'board' => ['board', 40],
            'task list' => ['list', 40],
            'task page (before deferred panels)' => ['task', 45],
            'timesheet' => ['timesheet', 25],
            'my tasks' => ['mine', 20],
            'invoices' => ['invoices', 20],
            'client billing' => ['billing', 35],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_stays_within_its_query_budget(string $page, int $budget)
    {
        $url = match ($page) {
            'dashboard' => route('dashboard'),
            'board' => route('workspaces.tasks.board', $this->ws),
            'list' => route('workspaces.tasks.index', $this->ws),
            'task' => route('workspaces.tasks.show', [$this->ws, $this->task]),
            'timesheet' => route('time.timesheet'),
            'mine' => route('tasks.mine'),
            'invoices' => route('billing.invoices.index'),
            'billing' => route('billing.clients.show', $this->ws),
        };

        $used = $this->queriesFor($url);
        $this->assertLessThanOrEqual($budget, $used, "{$page} used {$used} queries (budget {$budget}).");
    }
}
