<?php

namespace Tests\Feature\Workspaces;

use App\Models\Label;
use App\Models\Project;
use App\Models\RecordLink;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectsTaggingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);

        $this->workspace = Workspace::factory()->create(['name' => 'Home Client']);
        $this->member = $this->join($this->workspace);
    }

    private function join(Workspace $workspace, string $role = Workspace::ROLE_MEMBER): User
    {
        $user = User::factory()->withRole('member')->create();
        $workspace->members()->attach($user->id, ['role' => $role]);

        return $user;
    }

    public function test_project_people_must_be_workspace_members()
    {
        $outsider = User::factory()->create();

        $this->actingAs($this->member)->post(route('workspaces.projects.store', $this->workspace), [
            'name' => 'SEO audit',
            'type' => 'seo',
            'status' => 'planning',
            'lead_user_id' => $outsider->id,
            'member_ids' => [$outsider->id],
        ])->assertSessionHasErrors(['lead_user_id', 'member_ids.0']);
    }

    public function test_the_lead_is_always_on_the_team_and_labels_attach()
    {
        $label = Label::create(['workspace_id' => $this->workspace->id, 'name' => 'Urgent', 'color' => 'rose']);
        $colleague = $this->join($this->workspace);

        $this->actingAs($this->member)->post(route('workspaces.projects.store', $this->workspace), [
            'name' => 'Q4 campaign',
            'type' => 'campaign',
            'status' => 'active',
            'lead_user_id' => $colleague->id,
            'label_ids' => [$label->id],
            'due_on' => '2026-12-15',
        ])->assertRedirect();

        $project = Project::where('name', 'Q4 campaign')->firstOrFail();
        $this->assertTrue($project->members()->whereKey($colleague->id)->exists());
        $this->assertSame([$label->id], $project->labels()->pluck('labels.id')->all());
    }

    public function test_labels_from_another_workspace_are_rejected()
    {
        $foreign = Label::create(['workspace_id' => Workspace::factory()->create()->id, 'name' => 'X', 'color' => 'slate']);

        $this->actingAs($this->member)->post(route('workspaces.projects.store', $this->workspace), [
            'name' => 'P', 'type' => 'project', 'status' => 'planning', 'label_ids' => [$foreign->id],
        ])->assertSessionHasErrors('label_ids.0');
    }

    public function test_projects_are_scoped_to_their_workspace_in_the_url()
    {
        $otherWorkspace = Workspace::factory()->create();
        $foreignProject = Project::factory()->create(['workspace_id' => $otherWorkspace->id]);

        $this->actingAs($this->member)
            ->get(route('workspaces.projects.show', [$this->workspace, $foreignProject]))
            ->assertNotFound();
    }

    public function test_mentions_are_recorded_only_for_workspace_members()
    {
        $colleague = $this->join($this->workspace);
        $outsider = User::factory()->create(['name' => 'Out Sider']);

        $this->actingAs($this->member)->post(route('workspaces.projects.store', $this->workspace), [
            'name' => 'Launch',
            'type' => 'release',
            'status' => 'planning',
            'description' => "Hi @[{$colleague->name}](user:{$colleague->id}) and @[Out Sider](user:{$outsider->id})",
        ])->assertRedirect();

        $project = Project::where('name', 'Launch')->firstOrFail();

        $this->assertSame([$colleague->id], $project->mentions()->pluck('mentioned_user_id')->all());
        // The outsider's token is reduced to plain text so it cannot render as a tag.
        $this->assertStringContainsString('@Out Sider', (string) $project->description);
        $this->assertStringNotContainsString("(user:{$outsider->id})", (string) $project->description);
    }

    public function test_editing_the_brief_removes_stale_mentions()
    {
        $colleague = $this->join($this->workspace);
        $project = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $payload = ['name' => $project->name, 'type' => 'project', 'status' => 'active'];

        $this->actingAs($this->member)->put(route('workspaces.projects.update', [$this->workspace, $project]), [
            ...$payload, 'description' => "@[{$colleague->name}](user:{$colleague->id})",
        ]);
        $this->assertSame(1, $project->mentions()->count());

        $this->actingAs($this->member)->put(route('workspaces.projects.update', [$this->workspace, $project]), [
            ...$payload, 'description' => 'No tags now',
        ]);
        $this->assertSame(0, $project->mentions()->count());
    }

    public function test_cross_workspace_links_show_as_backlinks()
    {
        $otherWorkspace = Workspace::factory()->create();
        $otherWorkspace->members()->attach($this->member->id, ['role' => 'member']);
        $source = Project::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Website']);
        $target = Project::factory()->create(['workspace_id' => $otherWorkspace->id, 'name' => 'Shared SEO plan']);

        $this->actingAs($this->member)->post(route('links.store'), [
            'source_type' => 'project', 'source_id' => $source->id,
            'target_type' => 'project', 'target_id' => $target->id,
        ])->assertRedirect();

        $this->actingAs($this->member)
            ->get(route('workspaces.projects.show', [$otherWorkspace, $target]))
            ->assertInertia(fn ($page) => $page
                ->has('links', 1)
                ->where('links.0.direction', 'incoming')
                ->where('links.0.label', 'Website'));
    }

    public function test_cannot_link_to_records_you_cannot_see()
    {
        $hidden = Project::factory()->create(); // a workspace the member is not in
        $source = Project::factory()->create(['workspace_id' => $this->workspace->id]);

        $this->actingAs($this->member)->post(route('links.store'), [
            'source_type' => 'project', 'source_id' => $source->id,
            'target_type' => 'project', 'target_id' => $hidden->id,
        ])->assertForbidden();

        $this->assertSame(0, RecordLink::count());
    }

    public function test_backlinks_from_hidden_workspaces_are_not_leaked()
    {
        $hiddenWorkspace = Workspace::factory()->create();
        $hiddenProject = Project::factory()->create(['workspace_id' => $hiddenWorkspace->id, 'name' => 'Secret']);
        $visible = Project::factory()->create(['workspace_id' => $this->workspace->id]);

        RecordLink::create([
            'source_type' => 'project', 'source_id' => $hiddenProject->id,
            'target_type' => 'project', 'target_id' => $visible->id,
        ]);

        $this->actingAs($this->member)
            ->get(route('workspaces.projects.show', [$this->workspace, $visible]))
            ->assertInertia(fn ($page) => $page->has('links', 0));
    }

    public function test_reference_search_only_returns_visible_records()
    {
        Project::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Alpha launch']);
        Project::factory()->create(['name' => 'Alpha secret']); // other workspace

        $this->actingAs($this->member)
            ->getJson(route('references.search', ['q' => 'alpha']))
            ->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.label', 'Alpha launch');
    }

    public function test_only_leads_can_delete_projects()
    {
        $project = Project::factory()->create(['workspace_id' => $this->workspace->id]);
        $lead = $this->join($this->workspace, Workspace::ROLE_LEAD);

        $this->actingAs($this->member)
            ->delete(route('workspaces.projects.destroy', [$this->workspace, $project]))
            ->assertForbidden();

        $this->actingAs($lead)
            ->delete(route('workspaces.projects.destroy', [$this->workspace, $project]))
            ->assertRedirect();

        $this->assertSoftDeleted($project);
    }
}
