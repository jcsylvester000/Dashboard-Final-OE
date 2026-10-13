<?php

namespace Tests\Feature\Work;

use App\Domain\Files\DriveService;
use App\Domain\Work\TaskService;
use App\Models\Department;
use App\Models\DriveFile;
use App\Models\Mention;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Findings batch 7: recurring titles (R-23), typed @mentions (S3-03), drive share alerts (R-05),
 * multi-channel marketing details (S2-01).
 */
class FindingsBatchSevenTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $lead;

    private User $nico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->travelTo('2026-10-13 09:00:00');

        $this->ws = Workspace::factory()->create();
        $this->lead = User::factory()->withRole('member')->create(['name' => 'Jasmine Torres']);
        $this->nico = User::factory()->withRole('member')->create(['name' => 'Nico Santos']);
        $this->ws->members()->attach([$this->lead->id => ['role' => Workspace::ROLE_LEAD], $this->nico->id => ['role' => Workspace::ROLE_MEMBER]]);
    }

    public function test_recurring_titles_carry_numbers_and_dates_forward()
    {
        $svc = app(TaskService::class);
        $old = CarbonImmutable::parse('2026-10-16');
        $new = CarbonImmutable::parse('2026-10-23');

        $this->assertSame('Campaign results report – week 2', $svc->nextTitle('Campaign results report – week 1', $old, $new));
        $this->assertSame('Newsletter #8', $svc->nextTitle('Newsletter #7', $old, $new));
        $this->assertSame('SEO audit 2026-10-23', $svc->nextTitle('SEO audit 2026-10-16', $old, $new));
        $this->assertSame('Client call Oct 23', $svc->nextTitle('Client call Oct 16', $old, $new));
        $this->assertSame('Weekly SEO report', $svc->nextTitle('Weekly SEO report', $old, $new));

        $task = Task::factory()->create(['workspace_id' => $this->ws->id, 'title' => 'Report – week 1', 'due_on' => '2026-10-16', 'recurrence' => 'weekly']);
        $this->actingAs($this->lead)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['status_id' => TaskStatus::idFor('done')]);
        $this->assertSame('Report – week 2', Task::query()->where('recurred_from_task_id', $task->id)->value('title'));
    }

    public function test_typed_names_become_mentions_but_emails_and_strangers_do_not()
    {
        $outsider = User::factory()->withRole('member')->create(['name' => 'Ana Reyes']);
        $task = Task::factory()->create(['workspace_id' => $this->ws->id]);

        $this->actingAs($this->lead)->post(route('workspaces.tasks.comments.store', [$this->ws, $task]), [
            'body' => '@nico santos can you check? cc @Ana Reyes, mail me at jt@Nico.example',
        ])->assertSessionHasNoErrors();

        $this->assertSame([$this->nico->id], Mention::query()->pluck('mentioned_user_id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotContains($outsider->id, Mention::query()->pluck('mentioned_user_id')->all());

        // A unique first name works too; "@SEO" tags the department.
        $seo = Department::where('slug', 'seo')->firstOrFail();
        $grace = User::factory()->withRole('member')->create(['name' => 'Grace Lim', 'primary_department_id' => $seo->id]);
        $this->ws->members()->attach($grace->id, ['role' => Workspace::ROLE_MEMBER]);
        $other = Task::factory()->create(['workspace_id' => $this->ws->id]);
        $this->actingAs($this->lead)->post(route('workspaces.tasks.comments.store', [$this->ws, $other]), ['body' => 'Over to @SEO and @Nico'])
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$grace->id, $this->nico->id], Mention::query()->where('mentionable_id', '!=', $task->id)->pluck('mentioned_user_id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_sharing_a_drive_file_alerts_the_people_who_can_now_see_it_once()
    {
        $file = DriveFile::factory()->create(['owner_id' => $this->lead->id, 'name' => 'Holiday ad.png']);
        $drive = app(DriveService::class);

        $drive->share($file, 'workspace', $this->ws->id, null, $this->lead);
        $this->assertSame(1, $this->nico->notifications()->where('kind', 'file_shared')->count());
        $this->assertSame(0, $this->lead->notifications()->count(), 'no alert to yourself');
        $this->assertStringContainsString('Holiday ad.png', (string) $this->nico->notifications()->first()?->data['title']);

        // Saving the same sharing again is not a new share.
        $drive->share($file->fresh() ?? $file, 'workspace', $this->ws->id, null, $this->lead);
        $this->assertSame(1, $this->nico->notifications()->where('kind', 'file_shared')->count());

        // Public links alert nobody inside.
        $drive->share($file->fresh() ?? $file, 'public', null, 7, $this->lead);
        $this->assertSame(1, $this->nico->notifications()->where('kind', 'file_shared')->count());
    }

    public function test_marketing_tasks_take_several_channels_and_reject_unknown_ones()
    {
        $mkt = Department::where('slug', 'marketing')->firstOrFail();
        $task = Task::factory()->create(['workspace_id' => $this->ws->id, 'department_id' => $mkt->id]);

        $this->actingAs($this->lead)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['work_details' => ['channel' => 'Facebook, Instagram']])
            ->assertSessionHasNoErrors();
        $this->assertSame('Facebook, Instagram', $task->fresh()?->work_details['channel'] ?? null);

        $this->actingAs($this->lead)->put(route('workspaces.tasks.update', [$this->ws, $task]), ['work_details' => ['channel' => 'Facebook, MySpace']])
            ->assertSessionHasErrors('work_details.channel');
    }
}
