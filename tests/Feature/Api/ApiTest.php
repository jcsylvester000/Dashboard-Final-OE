<?php

namespace Tests\Feature\Api;

use App\Models\Department;
use App\Models\InboxNotification;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API v1 contract tests (P7): auth, abilities, workspace scoping, shapes,
 * rate limit, idempotency, work summary, token management.
 */
class ApiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $mine;

    private Workspace $other;

    private User $member;

    private User $lead;

    private Task $task;

    private Task $foreignTask;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);

        $this->mine = Workspace::factory()->create(['name' => 'Mine']);
        $this->other = Workspace::factory()->create(['name' => 'Other']);
        $this->member = User::factory()->withRole('member')->create();
        $this->lead = User::factory()->withRole('member')->create();
        $this->mine->members()->attach([$this->member->id => ['role' => Workspace::ROLE_MEMBER], $this->lead->id => ['role' => Workspace::ROLE_LEAD]]);

        $this->task = Task::factory()->create(['workspace_id' => $this->mine->id, 'title' => 'Mine task', 'assignee_id' => $this->member->id]);
        $this->foreignTask = Task::factory()->create(['workspace_id' => $this->other->id, 'title' => 'Secret task']);
    }

    /**
     * @param  list<string>  $abilities
     */
    private function token(User $user, array $abilities = ['read', 'write']): string
    {
        // The sanctum guard remembers the last user within one test; start fresh for each token.
        $this->app['auth']->forgetGuards();

        return $user->createToken('test', $abilities, now()->addDay())->plainTextToken;
    }

    public function test_requests_without_a_valid_token_get_401_json()
    {
        $this->getJson('/api/v1/me')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
        $this->get('/api/v1/tasks')->assertUnauthorized(); // no Accept header: still JSON, no redirect
        $this->withToken('nope')->getJson('/api/v1/me')->assertUnauthorized();

        $expired = $this->member->createToken('old', ['read'], now()->subMinute())->plainTextToken;
        $this->withToken($expired)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_me_returns_the_owner_and_abilities()
    {
        $this->withToken($this->token($this->member, ['read']))->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $this->member->id)
            ->assertJsonPath('data.email', $this->member->email)
            ->assertJsonPath('data.abilities', ['read'])
            ->assertJsonStructure(['data' => ['id', 'name', 'title', 'unread_notifications']]);
    }

    public function test_deactivated_members_tokens_stop_working()
    {
        $token = $this->token($this->member);
        $this->member->forceFill(['is_active' => false])->save();

        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_tasks_list_is_paginated_and_scoped_to_my_workspaces()
    {
        $this->withToken($this->token($this->member, ['read']))->getJson('/api/v1/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Mine task')
            ->assertJsonStructure([
                'data' => [['id', 'workspace_id', 'title', 'priority', 'status' => ['id', 'name', 'category'], 'assignee', 'due_on']],
                'links', 'meta' => ['current_page', 'per_page', 'total'],
            ]);

        $this->withToken($this->token($this->member, ['read']))->getJson('/api/v1/tasks?assignee=me')->assertJsonCount(1, 'data');
        $this->withToken($this->token($this->lead, ['read']))->getJson('/api/v1/tasks?assignee=me')->assertJsonCount(0, 'data');
    }

    public function test_cross_workspace_requests_are_refused()
    {
        $token = $this->token($this->member);

        $this->withToken($token)->getJson("/api/v1/tasks/{$this->foreignTask->id}")->assertForbidden();
        $this->withToken($token)->getJson("/api/v1/workspaces/{$this->other->id}")->assertForbidden();
        $this->withToken($token)->getJson("/api/v1/workspaces/{$this->other->id}/projects")->assertForbidden();
        $this->withToken($token)->postJson("/api/v1/workspaces/{$this->other->id}/tasks", ['title' => 'x'])->assertForbidden();
        $this->withToken($token)->postJson("/api/v1/tasks/{$this->foreignTask->id}/comments", ['body' => 'x'])->assertForbidden();

        // A task id under the wrong workspace is not found (scoped binding).
        $this->withToken($token)->patchJson("/api/v1/workspaces/{$this->mine->id}/tasks/{$this->foreignTask->id}", ['title' => 'x'])
            ->assertNotFound()->assertExactJson(['message' => 'Not found.']);
    }

    public function test_read_tokens_cannot_write()
    {
        $this->withToken($this->token($this->member, ['read']))
            ->postJson("/api/v1/workspaces/{$this->mine->id}/tasks", ['title' => 'From phone'])
            ->assertForbidden();

        $this->assertSame(0, Task::where('title', 'From phone')->count());
    }

    public function test_write_tokens_create_update_and_comment_with_the_web_rules()
    {
        $token = $this->token($this->member);
        $other = User::factory()->withRole('member')->create();
        $this->mine->members()->attach($other->id, ['role' => Workspace::ROLE_MEMBER]);

        $created = $this->withToken($token)->postJson("/api/v1/workspaces/{$this->mine->id}/tasks", [
            'title' => 'From phone', 'assignee_id' => $other->id, 'department_id' => Department::where('slug', 'seo')->value('id'),
        ])->assertCreated()->assertJsonPath('data.title', 'From phone')->assertJsonPath('data.department.slug', 'seo');
        $id = $created->json('data.id');

        // Same notifications as the web app.
        $this->assertSame(1, InboxNotification::where('notifiable_id', $other->id)->where('kind', 'assigned')->count());

        // Validation errors are JSON 422.
        $this->withToken($token)->postJson("/api/v1/workspaces/{$this->mine->id}/tasks", ['title' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors('title');

        $this->withToken($token)->patchJson("/api/v1/workspaces/{$this->mine->id}/tasks/{$id}", ['status_id' => TaskStatus::idFor('in-progress')])
            ->assertOk()->assertJsonPath('data.status.name', 'In Progress');

        $this->withToken($token)->postJson("/api/v1/tasks/{$id}/comments", ['body' => 'On it'])
            ->assertCreated()->assertJsonPath('data.body', 'On it')->assertJsonPath('data.author.id', $this->member->id);
        $this->withToken($token)->getJson("/api/v1/tasks/{$id}/comments")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_idempotency_key_replays_instead_of_creating_twice()
    {
        $token = $this->token($this->member);
        $headers = ['Idempotency-Key' => 'abc-123'];

        $first = $this->withToken($token)->withHeaders($headers)->postJson("/api/v1/workspaces/{$this->mine->id}/tasks", ['title' => 'Once']);
        $first->assertCreated();

        $second = $this->withToken($token)->withHeaders($headers)->postJson("/api/v1/workspaces/{$this->mine->id}/tasks", ['title' => 'Once']);
        $second->assertCreated()->assertHeader('Idempotent-Replayed', 'true');
        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, Task::where('title', 'Once')->count());

        // Same key on another endpoint is refused.
        $this->withToken($token)->withHeaders($headers)->postJson("/api/v1/tasks/{$this->task->id}/comments", ['body' => 'x'])
            ->assertUnprocessable();
    }

    public function test_rate_limit_returns_json_429()
    {
        config(['api.per_minute' => 2]);
        $token = $this->token($this->member, ['read']);

        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();
        $this->withToken($token)->getJson('/api/v1/me')
            ->assertStatus(429)
            ->assertJson(['message' => 'Too many requests. Slow down and retry shortly.'])
            ->assertHeader('Retry-After');
    }

    public function test_workspaces_members_projects_and_notifications()
    {
        Project::factory()->create(['workspace_id' => $this->mine->id, 'name' => 'Site']);
        $token = $this->token($this->member, ['read']);

        $this->withToken($token)->getJson('/api/v1/workspaces')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Mine');
        $members = $this->withToken($token)->getJson("/api/v1/workspaces/{$this->mine->id}/members")->assertOk()->assertJsonCount(2, 'data');
        // Only the token owner's own email is included.
        $this->assertSame([$this->member->email], collect($members->json('data'))->pluck('email')->filter()->values()->all());
        $this->withToken($token)->getJson("/api/v1/workspaces/{$this->mine->id}/projects")->assertOk()->assertJsonPath('data.0.name', 'Site');

        // An alert for the member, read through the API.
        $this->actingAs($this->lead)->post(route('workspaces.tasks.store', $this->mine), ['title' => 'For member', 'assignee_id' => $this->member->id]);
        $list = $this->withToken($this->token($this->member, ['read']))->getJson('/api/v1/notifications?unread=1')->assertOk()->assertJsonCount(1, 'data');
        $id = $list->json('data.0.id');

        $this->withToken($this->token($this->member))->postJson("/api/v1/notifications/{$id}/read")->assertOk()->assertJsonPath('data.read', true);
        $this->withToken($this->token($this->lead))->postJson("/api/v1/notifications/{$id}/read")->assertNotFound();
    }

    public function test_work_summary_feed_for_leads_only()
    {
        $seo = Department::where('slug', 'seo')->value('id');
        $seoTask = Task::factory()->create(['workspace_id' => $this->mine->id, 'department_id' => $seo, 'title' => 'Keyword map',
            'work_details' => ['keyword' => 'coffee manila', 'work_type' => 'Content']]);
        foreach ([[$this->member, $seoTask, 45], [$this->lead, $seoTask, 30], [$this->member, $this->task, 60]] as [$u, $t, $m]) {
            TimeEntry::create(['user_id' => $u->id, 'workspace_id' => $this->mine->id, 'task_id' => $t->id, 'department_id' => $t->department_id,
                'entry_date' => now()->toDateString(), 'minutes' => $m, 'is_billable' => true]);
        }
        $q = '?from='.now()->startOfMonth()->toDateString().'&to='.now()->toDateString();

        $this->withToken($this->token($this->member, ['read']))->getJson("/api/v1/workspaces/{$this->mine->id}/work-summary{$q}")->assertForbidden();

        $this->withToken($this->token($this->lead, ['read']))->getJson("/api/v1/workspaces/{$this->mine->id}/work-summary{$q}")
            ->assertOk()
            ->assertJsonPath('data.total_minutes', 135)
            ->assertJsonPath('data.seo.0.keyword', 'coffee manila')
            ->assertJsonPath('data.seo.0.minutes', 75)
            ->assertJsonCount(0, 'data.marketing');

        $this->withToken($this->token($this->lead, ['read']))->getJson("/api/v1/workspaces/{$this->mine->id}/work-summary{$q}&department=seo")
            ->assertOk()->assertJsonPath('data.total_minutes', 75);

        $this->withToken($this->token($this->lead, ['read']))->getJson("/api/v1/workspaces/{$this->mine->id}/work-summary")
            ->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_tokens_are_created_shown_once_and_revoked_from_settings()
    {
        $this->actingAs($this->member)->get(route('api-tokens.index'))->assertOk();

        $this->actingAs($this->member)->post(route('api-tokens.store'), ['name' => 'Phone', 'access' => 'read', 'days' => 30])
            ->assertRedirect(route('api-tokens.index'))
            ->assertSessionHas('plainToken');

        $token = $this->member->tokens()->firstOrFail();
        $this->assertSame(['read'], $token->abilities);
        $this->assertTrue($token->expires_at?->isBetween(now()->addDays(29), now()->addDays(31)));

        $this->actingAs($this->member)->post(route('api-tokens.store'), ['name' => 'Forever', 'access' => 'write', 'days' => 9999])
            ->assertSessionHasErrors('days');

        // Someone else cannot revoke it.
        $this->actingAs($this->lead)->delete(route('api-tokens.destroy', $token->id));
        $this->assertSame(1, $this->member->tokens()->count());

        $this->actingAs($this->member)->delete(route('api-tokens.destroy', $token->id));
        $this->assertSame(0, $this->member->tokens()->count());
    }
}
