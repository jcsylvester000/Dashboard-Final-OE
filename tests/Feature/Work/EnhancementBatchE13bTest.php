<?php

namespace Tests\Feature\Work;

use App\Domain\Notifications\NotificationKind;
use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\QuietHours;
use App\Models\InboxNotification;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enhancement batch E13b: quiet mode, per-task snooze and "only my reviews" (E-58),
 * the getting-started checklist (E-59). E-57 (save states) is front end only.
 */
class EnhancementBatchE13bTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $ana;

    private Task $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00')); // Monday, Manila
        $this->ws = Workspace::factory()->create();
        $this->ana = User::factory()->withRole('member')->create();
        $this->ws->members()->attach($this->ana->id, ['role' => Workspace::ROLE_MEMBER]);
        $this->task = Task::factory()->create(['workspace_id' => $this->ws->id]);
    }

    /** Send one alert to Ana and return it (earlier alerts are cleared first unless $keep). */
    private function alert(NotificationKind $kind = NotificationKind::Assigned, bool $keep = false): InboxNotification
    {
        if (! $keep) {
            DB::table('notifications')->delete();
        }
        app(Notifier::class)->send([$this->ana->id], $kind, $this->ws, ['title' => 'Heads up', 'url' => '/x', 'task_id' => $this->task->id]);

        return InboxNotification::query()->where('notifiable_id', $this->ana->id)->where('kind', $kind->value)->firstOrFail();
    }

    public function test_focus_now_makes_alerts_quiet_until_it_ends()
    {
        $this->assertFalse($this->alert()->quiet);

        $this->actingAs($this->ana)->post(route('notifications.focus'), ['for' => '1h'])->assertSessionHasNoErrors();
        $this->assertTrue($this->alert()->quiet, 'no pop-up while focusing; it waits in the digest');

        $this->travel(61)->minutes();
        $this->assertFalse($this->alert()->quiet);

        $this->actingAs($this->ana)->post(route('notifications.focus'), ['for' => 'today']);
        $this->actingAs($this->ana)->post(route('notifications.focus'), ['for' => 'off']);
        $this->assertNull($this->ana->fresh()?->focus_until);
    }

    public function test_daily_focus_window_and_after_hours()
    {
        $this->actingAs($this->ana)->put(route('notifications.quiet'), [
            'focus_from' => '13:00', 'focus_to' => '15:00', 'focus_days' => [1, 2, 3, 4, 5],
            'after_hours' => true, 'work_from' => '08:00', 'work_to' => '18:00', 'work_days' => [1, 2, 3, 4, 5],
        ])->assertSessionHasNoErrors();
        $user = $this->ana->fresh();
        $this->assertNotNull($user);
        $quiet = app(QuietHours::class);

        $this->assertFalse($quiet->isQuiet($user, CarbonImmutable::parse('2026-10-12 10:00')), 'Monday morning: live');
        $this->assertTrue($quiet->isQuiet($user, CarbonImmutable::parse('2026-10-12 14:00')), 'focus window');
        $this->assertTrue($quiet->isQuiet($user, CarbonImmutable::parse('2026-10-12 19:30')), 'after work');
        $this->assertTrue($quiet->isQuiet($user, CarbonImmutable::parse('2026-10-17 10:00')), 'Saturday');

        $this->actingAs($this->ana)->put(route('notifications.quiet'), ['work_from' => '8am', 'work_to' => '18:00'])->assertSessionHasErrors('work_from');
        $this->actingAs($this->ana)->get(route('notifications.edit'))
            ->assertInertia(fn (Assert $page) => $page->where('quiet.focus_from', '13:00')->where('quiet.after_hours', true));
    }

    public function test_snoozing_a_task_snoozes_its_alerts_and_only_my_reviews_filters_the_inbox()
    {
        $this->actingAs($this->ana)->post(route('workspaces.tasks.mute', [$this->ws, $this->task]), ['for' => 'tomorrow'])->assertSessionHasNoErrors();
        $alert = $this->alert();
        $this->assertSame('2026-10-13 09:00', $alert->snoozed_until?->format('Y-m-d H:i'));
        $this->assertTrue($alert->quiet);
        $this->actingAs($this->ana)->get(route('workspaces.tasks.show', [$this->ws, $this->task]))
            ->assertInertia(fn (Assert $page) => $page->where('task.muted_until', fn ($v) => str_starts_with((string) $v, '2026-10-13T09:00')));

        $this->actingAs($this->ana)->delete(route('workspaces.tasks.unmute', [$this->ws, $this->task]));
        $this->assertNull($this->alert()->snoozed_until);

        $this->alert(NotificationKind::Review, keep: true);
        $this->actingAs($this->ana)->get(route('inbox.index', ['tab' => 'all', 'only' => 'reviews']))
            ->assertInertia(fn (Assert $page) => $page->where('only', 'reviews')
                ->where('items.data', fn ($rows) => collect($rows)->pluck('kind')->unique()->values()->all() === ['review']));
    }

    public function test_getting_started_fits_the_role_ticks_itself_off_and_can_be_hidden()
    {
        $this->actingAs($this->ana)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->missing('gettingStarted')
                ->loadDeferredProps(fn (Assert $reload) => $reload->where('gettingStarted.done', 0)
                    ->where('gettingStarted.steps', fn ($steps) => collect($steps)->pluck('key')->all() === ['log_time', 'comment', 'upload_file', 'reply_pin'])));

        TimeEntry::create(['user_id' => $this->ana->id, 'workspace_id' => $this->ws->id, 'entry_date' => '2026-10-12', 'minutes' => 30, 'is_billable' => true]);
        $this->actingAs($this->ana)->get(route('getting-started.show'))
            ->assertInertia(fn (Assert $page) => $page->component('GettingStarted')->where('hidden', false)
                ->where('steps', fn ($steps) => collect($steps)->firstWhere('key', 'log_time')['done'] === true));

        $finance = User::factory()->withRole('finance')->create();
        $this->actingAs($finance)->get(route('getting-started.show'))
            ->assertInertia(fn (Assert $page) => $page->where('steps', fn ($steps) => collect($steps)->pluck('key')->contains('record_payment')));
        $admin = User::factory()->withRole('admin')->create();
        $this->actingAs($admin)->get(route('getting-started.show'))
            ->assertInertia(fn (Assert $page) => $page->where('steps', fn ($steps) => collect($steps)->pluck('key')->contains('privacy_notice')));

        $this->actingAs($this->ana)->post(route('getting-started.hide'))->assertSessionHasNoErrors();
        $this->actingAs($this->ana)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('gettingStarted', null)));
        $this->actingAs($this->ana)->get(route('getting-started.show'))->assertInertia(fn (Assert $page) => $page->where('hidden', true));

        $this->actingAs($this->ana)->post(route('getting-started.reopen'))->assertRedirect(route('dashboard'));
        $this->actingAs($this->ana)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->loadDeferredProps(fn (Assert $reload) => $reload->where('gettingStarted.done', 1)));
    }
}
