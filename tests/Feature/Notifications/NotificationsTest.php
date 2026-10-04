<?php

namespace Tests\Feature\Notifications;

use App\Events\Work\CommentAdded;
use App\Events\Work\HandoffReceived;
use App\Events\Work\TaskAssigned;
use App\Events\Work\TaskDueSoon;
use App\Events\Work\TaskOverdue;
use App\Events\Work\TaskStatusChanged;
use App\Events\Work\UserMentioned;
use App\Models\Department;
use App\Models\InboxNotification;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);

        $this->workspace = Workspace::factory()->create();
        $this->member = $this->join();
    }

    private function join(string $role = Workspace::ROLE_MEMBER, ?Workspace $workspace = null): User
    {
        $user = User::factory()->withRole('member')->create();
        ($workspace ?? $this->workspace)->members()->attach($user->id, ['role' => $role]);

        return $user;
    }

    private function dept(string $slug): Department
    {
        return Department::where('slug', $slug)->firstOrFail();
    }

    private function task(array $attributes = []): Task
    {
        return Task::factory()->create(['workspace_id' => $this->workspace->id, ...$attributes]);
    }

    private function alerts(User $user, ?string $kind = null): int
    {
        return InboxNotification::query()
            ->where('notifiable_id', $user->id)
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->count();
    }

    public function test_work_actions_dispatch_domain_events()
    {
        Event::fake([TaskAssigned::class, TaskStatusChanged::class, CommentAdded::class, UserMentioned::class, HandoffReceived::class]);
        $other = $this->join();

        $this->actingAs($this->member)->post(route('workspaces.tasks.store', $this->workspace), [
            'title' => 'Draft ad copy', 'assignee_id' => $other->id,
        ]);
        $task = Task::where('title', 'Draft ad copy')->firstOrFail();
        Event::assertDispatched(TaskAssigned::class, fn ($e) => $e->task->is($task));

        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->workspace, $task]), [
            'status_id' => TaskStatus::idFor('in-progress'),
        ]);
        Event::assertDispatched(TaskStatusChanged::class, fn ($e) => $e->fromStatusId === TaskStatus::idFor('todo'));

        $this->actingAs($this->member)->post(route('workspaces.tasks.comments.store', [$this->workspace, $task]), [
            'body' => "Thoughts @[{$other->name}](user:{$other->id})?",
        ]);
        Event::assertDispatched(CommentAdded::class, fn ($e) => $e->mentionedIds === [$other->id]);
        Event::assertDispatched(UserMentioned::class, fn ($e) => $e->userId === $other->id);

        $this->actingAs($this->member)->post(route('workspaces.tasks.handoff', [$this->workspace, $task]), [
            'department_id' => $this->dept('seo')->id,
        ]);
        Event::assertDispatched(HandoffReceived::class);
    }

    public function test_assignee_is_notified_but_never_the_person_who_acted()
    {
        $other = $this->join();

        $this->actingAs($this->member)->post(route('workspaces.tasks.store', $this->workspace), [
            'title' => 'Mine', 'assignee_id' => $this->member->id,
        ]);
        $this->actingAs($this->member)->post(route('workspaces.tasks.store', $this->workspace), [
            'title' => 'Theirs', 'assignee_id' => $other->id,
        ]);

        $this->assertSame(0, $this->alerts($this->member));
        $this->assertSame(1, $this->alerts($other, 'assigned'));

        $row = InboxNotification::where('notifiable_id', $other->id)->firstOrFail();
        $this->assertSame($this->workspace->id, $row->workspace_id);
        $this->assertStringStartsWith('/w/'.$this->workspace->slug.'/tasks/', $row->data['url']);
    }

    public function test_mentioned_people_get_a_mention_and_other_followers_get_a_comment_alert()
    {
        $watcher = $this->join();
        $tagged = $this->join();
        $task = $this->task();
        $task->watchers()->attach([$watcher->id, $this->member->id]);

        $this->actingAs($this->member)->post(route('workspaces.tasks.comments.store', [$this->workspace, $task]), [
            'body' => "Please check @[{$tagged->name}](user:{$tagged->id})",
        ])->assertRedirect();

        $this->assertSame(1, $this->alerts($tagged, 'mentioned'));
        $this->assertSame(0, $this->alerts($tagged, 'comment'));
        $this->assertSame(1, $this->alerts($watcher, 'comment'));
        $this->assertSame(0, $this->alerts($this->member));
    }

    public function test_handoff_reaches_the_department_lead_and_the_assignee_gets_one_alert()
    {
        $lead = $this->join();
        $this->dept('development')->update(['lead_user_id' => $lead->id]);
        $dev = $this->join();
        $research = $this->task(['department_id' => $this->dept('research')->id, 'assignee_id' => $this->member->id]);

        // No assignee: the receiving department's lead hears about it.
        $this->actingAs($this->member)->post(route('workspaces.tasks.handoff', [$this->workspace, $research]), [
            'department_id' => $this->dept('development')->id,
        ]);
        $this->assertSame(1, $this->alerts($lead, 'handoff'));

        // With an assignee: one handoff alert, no separate "assigned" alert.
        $this->actingAs($this->member)->post(route('workspaces.tasks.handoff', [$this->workspace, $research]), [
            'department_id' => $this->dept('development')->id,
            'assignee_id' => $dev->id,
        ]);
        $this->assertSame(1, $this->alerts($dev, 'handoff'));
        $this->assertSame(0, $this->alerts($dev, 'assigned'));

        // Finishing the source tells the waiting assignee they can start.
        $this->actingAs($this->member)->patch(route('workspaces.tasks.move', [$this->workspace, $research]), [
            'status_id' => TaskStatus::idFor('done'), 'position' => 1,
        ]);
        $this->assertSame(2, $this->alerts($dev, 'handoff'));
        $this->assertTrue(InboxNotification::where('notifiable_id', $dev->id)->get()->contains(fn ($n) => $n->data['title'] === 'Ready to start'));
    }

    public function test_preferences_off_and_digest_are_respected()
    {
        $other = $this->join();
        $other->forceFill(['notification_preferences' => ['assigned' => 'off', 'mentioned' => 'digest']])->save();
        $task = $this->task();

        $this->actingAs($this->member)->put(route('workspaces.tasks.update', [$this->workspace, $task]), [
            'assignee_id' => $other->id,
            'description' => "FYI @[{$other->name}](user:{$other->id})",
        ]);

        $this->assertSame(0, $this->alerts($other, 'assigned'));
        $mention = InboxNotification::where('notifiable_id', $other->id)->where('kind', 'mentioned')->firstOrFail();
        $this->assertTrue($mention->quiet);

        // Digest alerts are in the inbox but not on the bell.
        $this->actingAs($other)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('notifications.unread', 0)
                ->where('digest.unread', 1)
                ->has('digest.items', 1));
        $this->actingAs($other)->get(route('inbox.index'))
            ->assertInertia(fn (Assert $page) => $page->component('inbox/Index')->where('counts.attention', 1));
    }

    public function test_preferences_are_saved_and_validated()
    {
        $this->actingAs($this->member)->get(route('notifications.edit'))->assertOk();

        $modes = ['assigned' => 'realtime', 'mentioned' => 'realtime', 'handoff' => 'digest', 'comment' => 'off',
            'status' => 'off', 'due_soon' => 'digest', 'overdue' => 'realtime', 'escalation' => 'realtime'];

        $this->actingAs($this->member)->put(route('notifications.update'), ['modes' => $modes])
            ->assertRedirect(route('notifications.edit'));
        $this->assertSame($modes, $this->member->fresh()->notification_preferences);

        $this->actingAs($this->member)->put(route('notifications.update'), ['modes' => [...$modes, 'comment' => 'email']])
            ->assertSessionHasErrors('modes.comment');
    }

    public function test_alerts_never_reach_people_outside_the_workspace()
    {
        $outsider = User::factory()->withRole('member')->create();
        $task = $this->task();
        // Stale watcher row from someone who is not (or no longer) a member.
        $task->watchers()->attach($outsider->id);

        $this->actingAs($this->member)->post(route('workspaces.tasks.comments.store', [$this->workspace, $task]), [
            'body' => 'Update',
        ]);
        $this->assertSame(0, $this->alerts($outsider));

        // Someone removed from a workspace no longer sees its earlier alerts.
        $leaver = $this->join();
        $this->actingAs($this->member)->post(route('workspaces.tasks.store', $this->workspace), [
            'title' => 'For the leaver', 'assignee_id' => $leaver->id,
        ]);
        $this->assertSame(1, $this->alerts($leaver));
        $id = InboxNotification::where('notifiable_id', $leaver->id)->value('id');

        $this->workspace->members()->detach($leaver->id);

        $this->actingAs($leaver)->get(route('inbox.index', ['tab' => 'all']))
            ->assertInertia(fn (Assert $page) => $page->where('counts.all', 0));
        $this->actingAs($leaver)->get(route('inbox.open', $id))->assertNotFound();
    }

    public function test_people_cannot_act_on_someone_elses_alerts()
    {
        $other = $this->join();
        $this->actingAs($this->member)->post(route('workspaces.tasks.store', $this->workspace), [
            'title' => 'Theirs', 'assignee_id' => $other->id,
        ]);
        $id = InboxNotification::where('notifiable_id', $other->id)->value('id');

        $this->actingAs($this->member)->get(route('inbox.open', $id))->assertNotFound();
        $this->actingAs($this->member)->post(route('inbox.snooze', $id), ['until' => '1h'])->assertNotFound();
        $this->actingAs($this->member)->post(route('inbox.done'), ['ids' => [$id]]);

        $this->assertNull(InboxNotification::findOrFail($id)->done_at);
    }

    public function test_inbox_open_read_snooze_done_and_restore()
    {
        $other = $this->join();
        $this->actingAs($other)->post(route('workspaces.tasks.store', $this->workspace), [
            'title' => 'Review', 'assignee_id' => $this->member->id,
        ]);
        $alert = InboxNotification::where('notifiable_id', $this->member->id)->firstOrFail();
        $task = Task::where('title', 'Review')->firstOrFail();

        $this->actingAs($this->member)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('notifications.unread', 1));

        // Opening marks it read and goes to the task.
        $this->actingAs($this->member)->get(route('inbox.open', $alert->id))
            ->assertRedirect(route('workspaces.tasks.show', [$this->workspace, $task], false));
        $this->assertNotNull($alert->fresh()->read_at);

        // Assignments stay in "Needs my attention" until done or snoozed.
        $this->actingAs($this->member)->get(route('inbox.index'))
            ->assertInertia(fn (Assert $page) => $page->where('counts.attention', 1));

        $this->actingAs($this->member)->post(route('inbox.snooze', $alert->id), ['until' => 'tomorrow']);
        $this->actingAs($this->member)->get(route('inbox.index'))
            ->assertInertia(fn (Assert $page) => $page->where('counts.attention', 0)->where('counts.snoozed', 1));

        $this->actingAs($this->member)->post(route('inbox.restore', $alert->id));
        $this->actingAs($this->member)->post(route('inbox.done'), ['ids' => [$alert->id]]);
        $this->actingAs($this->member)->get(route('inbox.index'))
            ->assertInertia(fn (Assert $page) => $page->where('counts.attention', 0)->where('counts.done', 1));
    }

    public function test_hourly_alerts_fire_once_and_escalate_after_two_days()
    {
        $lead = $this->join();
        $this->dept('seo')->update(['lead_user_id' => $lead->id]);

        $soon = $this->task(['assignee_id' => $this->member->id, 'due_on' => now()->addDay()->toDateString()]);
        $late = $this->task(['assignee_id' => $this->member->id, 'department_id' => $this->dept('seo')->id, 'due_on' => now()->subDays(3)->toDateString()]);
        $this->task(['assignee_id' => $this->member->id, 'due_on' => now()->subDays(5)->toDateString(), 'status_id' => TaskStatus::idFor('done')]);

        $this->artisan('work:alerts')->assertSuccessful();
        $this->artisan('work:alerts')->assertSuccessful();

        $this->assertSame(1, $this->alerts($this->member, 'due_soon'));
        $this->assertSame(1, $this->alerts($this->member, 'overdue'));
        $this->assertSame(1, $this->alerts($lead, 'escalation'));

        // Moving the due date re-arms the alert.
        $soon->update(['due_on' => now()->toDateString()]);
        $this->artisan('work:alerts');
        $this->assertSame(2, $this->alerts($this->member, 'due_soon'));
        $this->assertSame(2, DB::table('task_alerts')->where('task_id', $late->id)->count());
    }

    public function test_alert_command_dispatches_due_events()
    {
        Event::fake([TaskDueSoon::class, TaskOverdue::class]);
        $this->task(['due_on' => now()->toDateString()]);
        $this->task(['due_on' => now()->subDay()->toDateString()]);

        $this->artisan('work:alerts');

        Event::assertDispatched(TaskDueSoon::class);
        Event::assertDispatched(TaskOverdue::class, fn ($e) => $e->escalate === false);
        Event::assertNotDispatched(TaskOverdue::class, fn ($e) => $e->escalate === true);
    }

    public function test_failed_jobs_page_is_for_system_managers_only()
    {
        DB::table('failed_jobs')->insert([
            'uuid' => '0b9c4f3e-8f69-4d34-9a59-1f2d3c4b5a6e',
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Notifications\\WorkNotification']),
            'exception' => "RuntimeException: boom\n#0 trace",
            'failed_at' => now(),
        ]);

        $this->actingAs($this->member)->get(route('admin.failed-jobs.index'))->assertForbidden();

        $admin = User::factory()->withRole('admin')->create();
        $this->actingAs($admin)->get(route('admin.failed-jobs.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->component('admin/failed-jobs/Index')
                ->where('jobs.data.0.job', 'WorkNotification')
                ->where('jobs.data.0.error', 'RuntimeException: boom'));

        $this->actingAs($admin)->post(route('admin.failed-jobs.destroy'), ['ids' => ['0b9c4f3e-8f69-4d34-9a59-1f2d3c4b5a6e']])
            ->assertRedirect();
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }
}
