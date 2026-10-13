<?php

namespace Tests\Feature\Work;

use App\Models\Attachment;
use App\Models\Comment;
use App\Models\Department;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase C of the 2026-10-06 simulation: project progress + task list + file
 * roll-up, and @Department mentions.
 */
class ProjectProgressTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');

        $this->ws = Workspace::factory()->create();
        $this->member = $this->join();
    }

    private function join(?Department $dept = null): User
    {
        $user = User::factory()->withRole('member')->create(['primary_department_id' => $dept?->id]);
        $this->ws->members()->attach($user->id, ['role' => Workspace::ROLE_MEMBER]);

        return $user;
    }

    public function test_the_project_page_shows_its_tasks_progress_and_task_files()
    {
        $project = Project::factory()->create(['workspace_id' => $this->ws->id]);
        $done = Task::factory()->done()->create(['workspace_id' => $this->ws->id, 'project_id' => $project->id, 'estimate_minutes' => 120]);
        Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $project->id, 'estimate_minutes' => 60, 'due_on' => now()->subDays(3)->toDateString()]);
        Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $project->id, 'status_id' => TaskStatus::idFor('in-progress')]);
        Task::factory()->create(['workspace_id' => $this->ws->id]); // not in the project

        $this->actingAs($this->member)->post(route('attachments.store'), [
            'attachable_type' => 'task',
            'attachable_id' => $done->id,
            'file' => UploadedFile::fake()->create('brief.pdf', 10, 'application/pdf'),
        ]);

        $this->actingAs($this->member)->get(route('workspaces.projects.show', [$this->ws, $project]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('tasks', 3)
                ->where('progress.total', 3)
                ->where('progress.done', 1)
                ->where('progress.percent', 33)
                ->where('progress.overdue', 1)
                ->where('progress.estimate_minutes', 180)
                ->where('progress.estimate_done_minutes', 120)
                ->has('taskFiles', 1)
                ->where('taskFiles.0.name', 'brief.pdf')
                ->where('taskFiles.0.task.id', $done->id)
                ->where('taskFiles.0.can_delete', false));

        $this->assertSame(1, Attachment::count());
    }

    public function test_tagging_a_department_notifies_its_members_on_this_client()
    {
        $seo = Department::where('slug', 'seo')->firstOrFail();
        $seoA = $this->join($seo);
        $seoB = $this->join($seo);
        $outsider = User::factory()->withRole('member')->create(['primary_department_id' => $seo->id]); // not on this client
        $task = Task::factory()->create(['workspace_id' => $this->ws->id]);

        $this->actingAs($this->member)->post(route('workspaces.tasks.comments.store', [$this->ws, $task]), [
            'body' => "@[SEO](dept:{$seo->id}) please add UTM tags",
        ])->assertSessionHasNoErrors();

        $comment = Comment::query()->latest('id')->firstOrFail();
        $this->assertStringContainsString("@[SEO](dept:{$seo->id})", $comment->body);
        foreach ([$seoA, $seoB] as $user) {
            $this->assertDatabaseHas('mentions', ['mentioned_user_id' => $user->id, 'mentionable_id' => $comment->id]);
        }
        $this->assertDatabaseMissing('mentions', ['mentioned_user_id' => $outsider->id]);
    }

    public function test_an_unknown_department_token_becomes_plain_text()
    {
        $task = Task::factory()->create(['workspace_id' => $this->ws->id]);

        $this->actingAs($this->member)->post(route('workspaces.tasks.comments.store', [$this->ws, $task]), [
            'body' => '@[Ghosts](dept:99999) hello',
        ]);

        $this->assertSame('@Ghosts hello', Comment::query()->latest('id')->value('body'));
    }
}
