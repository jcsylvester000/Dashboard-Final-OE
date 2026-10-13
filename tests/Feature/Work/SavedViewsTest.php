<?php

namespace Tests\Feature\Work;

use App\Models\SavedView;
use App\Models\Sprint;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Build 8d / brutal scenario M1: saved views, group-by, more filters.
 */
class SavedViewsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $lead;

    private User $member;

    private User $guest;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);

        $this->workspace = Workspace::factory()->create(['key' => 'BRW']);
        $this->lead = $this->join(Workspace::ROLE_LEAD);
        $this->member = $this->join(Workspace::ROLE_MEMBER);
        $this->guest = $this->join(Workspace::ROLE_GUEST);
    }

    public function test_personal_views_are_private_and_shared_views_reach_the_team()
    {
        $this->actingAs($this->member)->post(route('workspaces.views.store', $this->workspace), [
            'name' => 'My urgent bugs', 'page' => 'list', 'filters' => ['assignee' => 'me', 'type' => 'bug', 'search' => ''], 'shared' => false,
        ])->assertRedirect();
        $this->actingAs($this->member)->post(route('workspaces.views.store', $this->workspace), [
            'name' => 'Team this week', 'page' => 'board', 'filters' => ['due' => 'week', 'group' => 'assignee'], 'shared' => true,
        ])->assertRedirect();

        $this->assertSame(['assignee' => 'me', 'type' => 'bug'], SavedView::query()->firstWhere('name', 'My urgent bugs')?->filters);

        $names = fn (User $u) => array_column($this->prop($u, [], 'views'), 'name');
        $this->assertSame(['My urgent bugs', 'Team this week'], $names($this->member));
        $this->assertSame(['Team this week'], $names($this->lead));
    }

    public function test_saving_validates_keys_and_guests_cannot_share()
    {
        $this->actingAs($this->member)->post(route('workspaces.views.store', $this->workspace), [
            'name' => 'Bad', 'page' => 'list', 'filters' => ['password' => 'x'],
        ])->assertSessionHasErrors('filters');

        $this->actingAs($this->guest)->post(route('workspaces.views.store', $this->workspace), [
            'name' => 'Guest view', 'page' => 'list', 'filters' => ['due' => 'week'], 'shared' => true,
        ])->assertForbidden();

        $this->actingAs($this->guest)->post(route('workspaces.views.store', $this->workspace), [
            'name' => 'Guest view', 'page' => 'list', 'filters' => ['due' => 'week'],
        ])->assertRedirect();
        $this->assertDatabaseHas('saved_views', ['name' => 'Guest view', 'shared' => false]);

        $outsider = User::factory()->withRole('member')->create();
        $this->actingAs($outsider)->post(route('workspaces.views.store', $this->workspace), [
            'name' => 'x', 'page' => 'list', 'filters' => [],
        ])->assertForbidden();
    }

    public function test_only_the_owner_or_a_lead_deletes_a_shared_view()
    {
        $shared = SavedView::create(['workspace_id' => $this->workspace->id, 'user_id' => $this->member->id, 'name' => 'Team', 'page' => 'list', 'filters' => [], 'shared' => true]);
        $mine = SavedView::create(['workspace_id' => $this->workspace->id, 'user_id' => $this->member->id, 'name' => 'Mine', 'page' => 'list', 'filters' => [], 'shared' => false]);
        $other = User::factory()->withRole('member')->create();
        $this->workspace->members()->attach($other->id, ['role' => Workspace::ROLE_MEMBER]);

        $this->actingAs($other)->delete(route('workspaces.views.destroy', [$this->workspace, $shared]))->assertForbidden();
        $this->actingAs($this->lead)->delete(route('workspaces.views.destroy', [$this->workspace, $mine]))->assertForbidden();
        $this->actingAs($this->lead)->delete(route('workspaces.views.destroy', [$this->workspace, $shared]))->assertRedirect();
        $this->actingAs($this->member)->delete(route('workspaces.views.destroy', [$this->workspace, $mine]))->assertRedirect();

        $this->assertSame(0, SavedView::query()->count());
    }

    public function test_views_are_scoped_to_their_client()
    {
        $foreign = SavedView::create(['workspace_id' => Workspace::factory()->create()->id, 'user_id' => $this->member->id, 'name' => 'X', 'page' => 'list', 'filters' => [], 'shared' => false]);

        $this->actingAs($this->member)->delete(route('workspaces.views.destroy', [$this->workspace, $foreign]))->assertNotFound();
    }

    public function test_list_groups_by_person_and_filters_by_type_and_sprint()
    {
        $sprint = Sprint::factory()->create(['workspace_id' => $this->workspace->id]);
        $sprint->forceFill(['status' => Sprint::ACTIVE])->save();
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'assignee_id' => $this->member->id, 'title' => 'B member task']);
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'assignee_id' => null, 'title' => 'C nobody']);
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'assignee_id' => $this->lead->id, 'title' => 'A lead bug', 'type' => 'bug', 'sprint_id' => $sprint->id]);
        Task::factory()->create(['workspace_id' => $this->workspace->id, 'assignee_id' => $this->member->id, 'title' => 'D member task 2']);

        $page = fn (array $q) => $this->prop($this->member, $q, 'tasks.data');

        // Grouped by person: same assignee rows are contiguous, unassigned last.
        $owners = array_map(fn ($t) => $t['assignee']['id'] ?? null, $page(['group' => 'assignee']));
        $this->assertNull(end($owners));
        $this->assertSame(2, count(array_filter($owners, fn ($o) => $o === $this->member->id)));
        $firstMember = array_search($this->member->id, $owners, true);
        $this->assertSame($this->member->id, $owners[$firstMember + 1]);

        $this->assertSame(['A lead bug'], array_column($page(['type' => 'bug']), 'title'));
        $this->assertSame(['A lead bug'], array_column($page(['sprint' => 'active']), 'title'));
        $this->assertCount(3, $page(['sprint' => 'backlog']));
        $this->actingAs($this->member)->get(route('workspaces.tasks.index', [$this->workspace, 'group' => 'colour']))->assertSessionHasErrors('group');
    }

    /**
     * A prop from the task list page, as a plain array.
     *
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function prop(User $user, array $query, string $key): array
    {
        $value = [];
        $this->actingAs($user)->get(route('workspaces.tasks.index', [$this->workspace, ...$query]))
            ->assertOk()
            // A full closure (not fn): arrow functions capture by value, so &$value would never come back.
            ->assertInertia(function ($page) use ($key, &$value) {
                $page->where($key, function ($v) use (&$value) {
                    $value = json_decode((string) json_encode($v), true);

                    return true;
                });
            });

        return $value;
    }

    private function join(string $role): User
    {
        $user = User::factory()->withRole('member')->create();
        $this->workspace->members()->attach($user->id, ['role' => $role]);

        return $user;
    }
}
