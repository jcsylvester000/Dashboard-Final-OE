<?php

namespace Tests\Feature\Work;

use App\Http\Controllers\SearchController;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Build 8b: task keys and types (J2), global search + short links (J3), bulk edit (M2).
 */
class TaskKeysSearchBulkTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);

        $this->workspace = Workspace::factory()->create(['name' => 'Brewhouse Coffee', 'key' => 'BRW']);
        $this->member = User::factory()->withRole('member')->create();
        $this->workspace->members()->attach($this->member->id, ['role' => Workspace::ROLE_MEMBER]);
    }

    public function test_keys_are_suggested_from_the_client_name_and_kept_unique()
    {
        $this->assertSame('DSR', Workspace::suggestKey('Desert Sun Realty'));
        $this->assertSame('BRE', Workspace::suggestKey('Brewhouse'));
        $this->assertSame('DSR2', Workspace::suggestKey('Desert Sun Realty', ['DSR']));
        $this->assertSame('W42', Workspace::suggestKey('42'));

        $created = Workspace::factory()->create(['name' => 'Bright Smile Dental']);
        $this->assertSame('BSD', $created->key);
        $this->assertSame('BSD2', Workspace::factory()->create(['name' => 'Bright Smile Dental'])->key);
    }

    public function test_tasks_are_numbered_per_client_and_numbers_are_never_reused()
    {
        $other = Workspace::factory()->create(['key' => 'DSR']);
        $a = Task::factory()->create(['workspace_id' => $this->workspace->id]);
        $b = Task::factory()->create(['workspace_id' => $this->workspace->id]);
        $c = Task::factory()->create(['workspace_id' => $other->id]);
        $b->delete();
        $d = Task::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->assertSame([1, 2, 1, 3], [$a->number, $b->number, $c->number, $d->number]);
        $this->assertSame('BRW-3', $d->load('workspace')->keyLabel());
        $this->assertTrue(Task::findByKey('brw-3')?->is($d));
        $this->assertNull(Task::findByKey('BRW-2'));   // soft-deleted
        $this->assertNull(Task::findByKey('nonsense'));

        $this->actingAs($this->member)->get(route('workspaces.tasks.index', $this->workspace))
            ->assertInertia(fn ($page) => $page->where('tasks.data.0.key', fn ($k) => str_starts_with((string) $k, 'BRW-')));
    }

    public function test_short_link_opens_the_task_only_for_people_who_can_see_it()
    {
        $task = Task::factory()->create(['workspace_id' => $this->workspace->id]);
        $outsider = User::factory()->withRole('member')->create();

        $this->actingAs($this->member)->get('/t/BRW-'.$task->number)
            ->assertRedirect(route('workspaces.tasks.show', [$this->workspace, $task]));
        $this->actingAs($outsider)->get('/t/BRW-'.$task->number)->assertForbidden();
        $this->actingAs($this->member)->get('/t/BRW-999')->assertNotFound();
    }

    public function test_task_type_is_saved_and_validated()
    {
        $this->actingAs($this->member)->post(route('workspaces.tasks.store', $this->workspace), ['title' => 'Checkout crashes', 'type' => 'bug'])
            ->assertRedirect();
        $this->assertSame('bug', Task::query()->where('title', 'Checkout crashes')->value('type'));

        $this->actingAs($this->member)->post(route('workspaces.tasks.store', $this->workspace), ['title' => 'X', 'type' => 'epic'])
            ->assertSessionHasErrors('type');
    }

    public function test_search_understands_keys_text_and_filters_and_never_leaks_other_clients()
    {
        $bug = Task::factory()->create(['workspace_id' => $this->workspace->id, 'title' => 'Duplicate order on double tap', 'type' => 'bug', 'priority' => 'urgent', 'assignee_id' => $this->member->id]);
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'title' => 'Menu page copy', 'type' => 'task']);
        $hidden = Workspace::factory()->create(['key' => 'SEC']);
        Task::factory()->create(['workspace_id' => $hidden->id, 'title' => 'Duplicate invoice secret', 'type' => 'bug']);

        $search = fn (string $q) => $this->actingAs($this->member)->getJson(route('search', ['q' => $q]))->assertOk()->json('tasks');

        $this->assertSame([$bug->id], array_column($search('BRW-'.$bug->number), 'id'));
        $this->assertSame([$bug->id], array_column($search('duplicate'), 'id'));
        $this->assertSame([$bug->id], array_column($search('type:bug priority:urgent @me in:BRW open'), 'id'));
        $this->assertSame([], $search('SEC-1'));
        $this->assertSame('BRW-'.$bug->number, $search('#'.$bug->id)[0]['key']);

        $parsed = SearchController::parse('type:story @me login');
        $this->assertSame(['story', true, 'login', true], [$parsed['type'], $parsed['mine'], $parsed['text'], $parsed['filtered']]);
    }

    public function test_bulk_edit_reassigns_reprioritises_and_shifts_due_dates()
    {
        $nico = User::factory()->withRole('member')->create();
        $this->workspace->members()->attach($nico->id, ['role' => Workspace::ROLE_MEMBER]);
        $tasks = Task::factory()->count(3)->create(['workspace_id' => $this->workspace->id, 'due_on' => '2026-10-10', 'priority' => 'normal']);
        $untouched = Task::factory()->create(['workspace_id' => $this->workspace->id, 'due_on' => '2026-10-10']);

        $this->actingAs($this->member)->post(route('workspaces.tasks.bulk', $this->workspace), [
            'ids' => $tasks->pluck('id')->all(),
            'action' => 'update',
            'assignee_id' => (string) $nico->id,
            'priority' => 'high',
            'due_shift_days' => 3,
        ])->assertRedirect()->assertSessionHasNoErrors();

        foreach ($tasks as $t) {
            $t->refresh();
            $this->assertSame([$nico->id, 'high', '2026-10-13'], [$t->assignee_id, $t->priority, $t->due_on?->toDateString()]);
        }
        $this->assertSame('2026-10-10', $untouched->fresh()->due_on?->toDateString());
        $this->assertDatabaseHas('activity_log', ['action' => 'task.updated', 'subject_id' => $tasks[0]->id]);
    }

    public function test_bulk_edit_skips_tasks_that_cannot_finish_and_ignores_other_clients()
    {
        $blocker = Task::factory()->create(['workspace_id' => $this->workspace->id]);
        $waiting = Task::factory()->create(['workspace_id' => $this->workspace->id]);
        $waiting->dependencies()->attach($blocker->id, ['type' => 'blocks']);
        $free = Task::factory()->create(['workspace_id' => $this->workspace->id]);
        $foreign = Task::factory()->create(['workspace_id' => Workspace::factory()->create()->id]);

        $this->actingAs($this->member)->post(route('workspaces.tasks.bulk', $this->workspace), [
            'ids' => [$waiting->id, $free->id, $foreign->id],
            'action' => 'update',
            'status_id' => TaskStatus::idFor('done'),
        ])->assertRedirect();

        $this->assertSame(TaskStatus::idFor('done'), $free->fresh()->status_id);
        $this->assertNotSame(TaskStatus::idFor('done'), $waiting->fresh()->status_id);
        $this->assertNotSame(TaskStatus::idFor('done'), $foreign->fresh()->status_id);
    }

    public function test_bulk_edit_validates_and_guests_cannot_use_it()
    {
        $task = Task::factory()->create(['workspace_id' => $this->workspace->id]);
        $outsider = User::factory()->withRole('member')->create();
        $guest = User::factory()->withRole('member')->create();
        $this->workspace->members()->attach($guest->id, ['role' => Workspace::ROLE_GUEST]);

        $this->actingAs($this->member)->post(route('workspaces.tasks.bulk', $this->workspace), ['ids' => [$task->id], 'action' => 'update'])
            ->assertSessionHasErrors('action');
        $this->actingAs($this->member)->post(route('workspaces.tasks.bulk', $this->workspace), ['ids' => [$task->id], 'action' => 'update', 'assignee_id' => (string) $outsider->id])
            ->assertSessionHasErrors('assignee_id');
        $this->actingAs($guest)->post(route('workspaces.tasks.bulk', $this->workspace), ['ids' => [$task->id], 'action' => 'delete'])
            ->assertForbidden();
        $this->assertNotNull($task->fresh());
    }

    public function test_owners_can_change_the_key_and_it_must_be_unique()
    {
        Workspace::factory()->create(['key' => 'DSR']);
        $owner = User::factory()->withRole('member')->create();
        $this->workspace->members()->attach($owner->id, ['role' => Workspace::ROLE_OWNER]);
        $base = ['name' => $this->workspace->name, 'color' => 'blue', 'status' => 'active'];

        $this->actingAs($owner)->put(route('workspaces.update', $this->workspace), [...$base, 'key' => 'dsr'])->assertSessionHasErrors('key');
        $this->actingAs($owner)->put(route('workspaces.update', $this->workspace), [...$base, 'key' => '1AB'])->assertSessionHasErrors('key');
        $this->actingAs($owner)->put(route('workspaces.update', $this->workspace), [...$base, 'key' => 'bcc'])->assertSessionHasNoErrors();
        $this->assertSame('BCC', $this->workspace->fresh()->key);
    }

    public function test_the_task_picker_searches_one_client_skips_the_current_task_and_done_work()
    {
        $current = Task::factory()->create(['workspace_id' => $this->workspace->id, 'title' => 'Checkout page']);
        $open = Task::factory()->create(['workspace_id' => $this->workspace->id, 'title' => 'Checkout API']);
        Task::factory()->done()->create(['workspace_id' => $this->workspace->id, 'title' => 'Checkout copy']);
        $elsewhere = Workspace::factory()->create();
        $elsewhere->members()->attach($this->member->id, ['role' => Workspace::ROLE_MEMBER]);
        Task::factory()->create(['workspace_id' => $elsewhere->id, 'title' => 'Checkout other client']);

        $ids = fn (array $q) => array_column($this->actingAs($this->member)->getJson(route('search', $q))->assertOk()->json('tasks'), 'id');

        $this->assertSame([$open->id], $ids(['q' => 'checkout', 'workspace' => $this->workspace->id, 'exclude' => $current->id]));
        // Empty box in a picker: the client's recent open work, not just mine.
        $this->assertEqualsCanonicalizing([$open->id], $ids(['q' => '', 'workspace' => $this->workspace->id, 'exclude' => $current->id]));
        // The task page no longer ships every task as options (B-21).
        $this->actingAs($this->member)->get(route('workspaces.tasks.show', [$this->workspace, $current]))
            ->assertInertia(fn ($page) => $page->missing('dependencyOptions'));
    }
}
