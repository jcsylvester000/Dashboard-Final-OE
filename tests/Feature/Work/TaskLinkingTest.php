<?php

namespace Tests\Feature\Work;

use App\Models\RecordLink;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Members add their own tasks from anywhere and link them to existing tickets;
 * links show on rows (count) and on the task page (Linked work).
 */
class TaskLinkingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->ws = Workspace::factory()->create();
        $this->member = User::factory()->withRole('member')->create();
        $this->ws->members()->attach($this->member->id, ['role' => Workspace::ROLE_MEMBER]);
    }

    public function test_the_new_task_dialog_lists_only_clients_i_can_add_work_to()
    {
        $guestOn = Workspace::factory()->create(['name' => 'Read only client']);
        $guestOn->members()->attach($this->member->id, ['role' => Workspace::ROLE_GUEST]);

        $this->actingAs($this->member)->getJson(route('tasks.new-options'))
            ->assertOk()
            ->assertJsonCount(1, 'workspaces')
            ->assertJsonPath('workspaces.0.id', $this->ws->id)
            ->assertJsonPath('me.id', $this->member->id);
    }

    public function test_a_member_adds_their_own_task_linked_to_an_existing_ticket()
    {
        $ticket = Task::factory()->create(['workspace_id' => $this->ws->id, 'title' => 'Checkout bug']);

        $this->actingAs($this->member)->post(route('workspaces.tasks.store', $this->ws), [
            'title' => 'Write regression test for checkout bug',
            'assignee_id' => $this->member->id,
            'link_task_id' => $ticket->id,
            'link_relation' => 'follows',
            'stay' => 1,
        ])->assertSessionHasNoErrors();

        $mine = Task::where('title', 'Write regression test for checkout bug')->firstOrFail();
        $this->assertSame($this->member->id, $mine->assignee_id);
        $this->assertDatabaseHas('record_links', ['source_id' => $mine->id, 'target_id' => $ticket->id, 'relation' => 'follows']);

        // Both tasks show as linked in lists, and the task page lists the link with its type.
        $this->actingAs($this->member)->get(route('workspaces.tasks.index', $this->ws))
            ->assertInertia(fn (Assert $page) => $page->where('tasks.data', fn ($rows) => collect($rows)->every(fn ($r) => $r['linked'] === 1)));
        $this->actingAs($this->member)->get(route('workspaces.tasks.show', [$this->ws, $ticket]))
            ->assertInertia(fn (Assert $page) => $page->where('linkedWork.0.relation', 'Has follow-up')->where('linkedWork.0.title', $mine->title));
    }

    public function test_blocking_links_create_dependencies_but_only_inside_one_client()
    {
        $ticket = Task::factory()->create(['workspace_id' => $this->ws->id]);
        $this->actingAs($this->member)->post(route('workspaces.tasks.store', $this->ws), [
            'title' => 'Waits on the ticket', 'link_task_id' => $ticket->id, 'link_relation' => 'blocked_by', 'stay' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertTrue(Task::where('title', 'Waits on the ticket')->firstOrFail()->dependencies()->whereKey($ticket->id)->exists());

        $other = Workspace::factory()->create();
        $other->members()->attach($this->member->id, ['role' => Workspace::ROLE_MEMBER]);
        $elsewhere = Task::factory()->create(['workspace_id' => $other->id]);
        $this->actingAs($this->member)->post(route('workspaces.tasks.store', $this->ws), [
            'title' => 'Cross-client block', 'link_task_id' => $elsewhere->id, 'link_relation' => 'blocks',
        ])->assertSessionHasErrors('link_relation');
        $this->assertFalse(Task::where('title', 'Cross-client block')->exists());

        // Can't link to a task you can't see.
        $hidden = Task::factory()->create();
        $this->actingAs($this->member)->post(route('workspaces.tasks.store', $this->ws), [
            'title' => 'Sneaky', 'link_task_id' => $hidden->id, 'link_relation' => 'relates',
        ])->assertForbidden();
    }

    public function test_references_carry_a_link_type()
    {
        $a = Task::factory()->create(['workspace_id' => $this->ws->id]);
        $b = Task::factory()->create(['workspace_id' => $this->ws->id]);

        $this->actingAs($this->member)->post(route('links.store'), [
            'source_type' => 'task', 'source_id' => $a->id, 'target_type' => 'task', 'target_id' => $b->id, 'relation' => 'duplicates',
        ])->assertSessionHasNoErrors();

        $this->assertSame('duplicates', RecordLink::firstOrFail()->relation);
        $this->actingAs($this->member)->get(route('workspaces.tasks.show', [$this->ws, $b]))
            ->assertInertia(fn (Assert $page) => $page->where('links.0.relation_label', 'Is duplicated by'));
    }
}
