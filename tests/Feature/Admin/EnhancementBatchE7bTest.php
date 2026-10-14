<?php

namespace Tests\Feature\Admin;

use App\Console\Commands\ArchiveActivityLog;
use App\Domain\System\HealthChecks;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\InboxNotification;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enhancement batch E7b: activity log filters, export and archive (E-91), bulk import,
 * export and planned end of access (E-93), department archive (E-94), holiday import and
 * reminder (E-95), system health (E-96), admin API tokens (E-97).
 */
class EnhancementBatchE7bTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo('2026-11-03 09:00:00');
        $this->admin = User::factory()->withRole('admin')->create();
    }

    private function log(string $action, string $at, array $props = []): void
    {
        ActivityLog::query()->insert(['action' => $action, 'properties' => json_encode($props), 'created_at' => $at]);
    }

    public function test_activity_filters_export_and_archive()
    {
        $this->log('user.updated', '2026-10-01 10:00:00', ['before' => ['email' => 'a@x.test'], 'after' => ['email' => 'b@x.test']]);
        $this->log('user.updated', '2026-11-02 10:00:00');
        $this->log('billing.invoice-finalized', '2023-05-01 10:00:00');
        $this->log('auth.login', '2023-05-02 10:00:00');

        $this->actingAs($this->admin)->get(route('admin.activity.index', ['action' => 'user.', 'from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertInertia(fn (Assert $page) => $page->has('logs.data', 1)->where('logs.data.0.properties.after.email', 'b@x.test'));

        $csv = $this->actingAs($this->admin)->get(route('admin.activity.csv', ['action' => 'user.', 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->streamedContent();
        $this->assertSame(2, substr_count(trim($csv), "\n") + 1, 'header + the one filtered row');
        $this->assertStringContainsString('b@x.test', $csv);

        // 24 months online: the 2023 login moves to the archive, the 2023 billing row stays (10 years).
        $this->artisan('activity:archive')->assertSuccessful();
        $this->artisan('activity:archive')->assertSuccessful();
        $this->assertFalse(ActivityLog::query()->where('action', 'auth.login')->exists());
        $this->assertTrue(ActivityLog::query()->where('action', 'billing.invoice-finalized')->exists());
        $archived = Storage::disk('local')->get(ArchiveActivityLog::DIR.'/2023-05.csv');
        $this->assertSame(1, substr_count((string) $archived, 'auth.login'), 'written once even after a rerun');
    }

    public function test_bulk_import_previews_then_adds_valid_rows_with_setup_links()
    {
        $csv = "name,email,role,department,title,access_ends_on\n"
            ."Ana Cruz,ana@overeasy.test,Member,Marketing,Designer,\n"
            ."Ben Lim,ben@overeasy.test,member,seo,,2026-12-31\n"
            ."No Email,,member,,,\n"
            ."Ana Again,ana@overeasy.test,member,,,\n"
            ."Boss,boss@overeasy.test,super-admin,,,\n"
            ."Odd Dept,odd@overeasy.test,member,Kitchen,,\n";
        $file = fn () => UploadedFile::fake()->createWithContent('team.csv', $csv);

        $this->actingAs($this->admin)->post(route('admin.users.bulk-import'), ['file' => $file(), 'preview' => true])->assertSessionHas('userImport');
        $this->assertFalse(User::query()->where('email', 'ana@overeasy.test')->exists(), 'preview saves nothing');

        $this->actingAs($this->admin)->post(route('admin.users.bulk-import'), ['file' => $file(), 'preview' => false])
            ->assertSessionHas('userImport', fn ($r) => count($r['created']) === 2 && str_contains($r['created'][0]['link'], '/access/')
                && collect($r['rows'])->where('status', 'error')->count() === 4);
        $ben = User::query()->where('email', 'ben@overeasy.test')->firstOrFail();
        $this->assertSame('2026-12-31', $ben->access_ends_on?->toDateString());
        $this->assertSame('seo', Department::query()->find($ben->primary_department_id)?->slug);
        $this->assertTrue($ben->hasRole('member'));

        $export = $this->actingAs($this->admin)->get(route('admin.users.csv'))->assertOk()->streamedContent();
        $this->assertStringContainsString('ben@overeasy.test', $export);
    }

    public function test_planned_end_of_access_warns_the_lead_then_deactivates()
    {
        $lead = User::factory()->withRole('member')->create();
        $dept = Department::query()->where('slug', 'marketing')->firstOrFail();
        $dept->forceFill(['lead_user_id' => $lead->id])->save();
        $contractor = User::factory()->withRole('member')->create(['primary_department_id' => $dept->id, 'access_ends_on' => '2026-11-08']);
        $contractor->createToken('phone', ['read']);

        $this->artisan('users:expire-access');
        $this->artisan('users:expire-access');
        $this->assertSame(1, InboxNotification::where('notifiable_id', $lead->id)->where('kind', 'system')->count());

        $this->travelTo('2026-11-09 06:10:00');
        $this->artisan('users:expire-access');
        $contractor->refresh();
        $this->assertFalse($contractor->is_active);
        $this->assertSame(0, $contractor->tokens()->count());
    }

    public function test_archiving_a_department_moves_people_and_open_tasks_and_hides_it_from_pickers()
    {
        $seo = Department::query()->where('slug', 'seo')->firstOrFail();
        $marketing = Department::query()->where('slug', 'marketing')->firstOrFail();
        $person = User::factory()->withRole('member')->create(['primary_department_id' => $seo->id]);
        $person->departments()->sync([$seo->id]);
        $ws = Workspace::factory()->create();
        $open = Task::factory()->create(['workspace_id' => $ws->id, 'department_id' => $seo->id]);
        $done = Task::factory()->create(['workspace_id' => $ws->id, 'department_id' => $seo->id, 'completed_at' => now()]);

        $this->actingAs($this->admin)->post(route('admin.departments.archive', $seo), ['move_to' => $seo->id])->assertSessionHasErrors('move_to');
        $this->actingAs($this->admin)->post(route('admin.departments.archive', $seo), ['move_to' => $marketing->id])->assertSessionHasNoErrors();

        $this->assertNotNull($seo->fresh()?->archived_at);
        $this->assertSame($marketing->id, $person->fresh()?->primary_department_id);
        $this->assertTrue($person->fresh()?->departments()->whereKey($marketing->id)->exists());
        $this->assertSame($marketing->id, $open->fresh()?->department_id);
        $this->assertSame($seo->id, $done->fresh()?->department_id, 'finished work keeps its department for reports');

        $this->actingAs($this->admin)->get(route('admin.users.create'))
            ->assertInertia(fn (Assert $page) => $page->where('departments', fn ($d) => ! collect($d)->contains('id', $seo->id)));

        $this->actingAs($this->admin)->post(route('admin.departments.restore', $seo));
        $this->assertNull($seo->fresh()?->archived_at);
    }

    public function test_holiday_list_import_skips_duplicates_and_reminds_once_in_december()
    {
        Holiday::create(['date' => '2027-01-01', 'name' => "New Year's Day", 'kind' => 'regular']);
        $text = "2027-01-01, New Year's Day, regular\n2027-02-25, EDSA Anniversary, special working\n2027-04-09, Araw ng Kagitingan\n2027-13-01, Bad date\n";

        $this->actingAs($this->admin)->post(route('admin.holidays.bulk'), ['text' => $text, 'preview' => true])
            ->assertSessionHas('holidayImport', fn ($r) => collect($r['rows'])->pluck('status')->all() === ['duplicate', 'new', 'new', 'error']);
        $this->assertSame(1, Holiday::query()->count());

        $this->actingAs($this->admin)->post(route('admin.holidays.bulk'), ['text' => $text, 'preview' => false])->assertSessionHasNoErrors();
        $this->assertSame(3, Holiday::query()->count());
        $this->assertSame('special_working', Holiday::query()->whereDate('date', '2027-02-25')->value('kind'));

        $this->travelTo('2027-12-01 09:00:00');
        $this->artisan('holidays:remind');
        $this->artisan('holidays:remind');
        $this->assertSame(1, InboxNotification::where('notifiable_id', $this->admin->id)->where('kind', 'system')->count());
    }

    public function test_health_page_and_alerts_when_the_scheduler_stops()
    {
        Cache::put(HealthChecks::HEARTBEAT_KEY, now()->toIso8601String());
        $this->actingAs($this->admin)->get(route('admin.health'))
            ->assertInertia(fn (Assert $page) => $page->component('admin/Health')
                ->where('checks', fn ($c) => collect($c)->firstWhere('key', 'scheduler')['status'] === 'ok'));
        $this->artisan('health:check')->assertSuccessful();
        $this->assertSame(0, InboxNotification::where('notifiable_id', $this->admin->id)->count());

        $this->travelTo('2026-11-03 09:07:00'); // heartbeat 7 minutes old
        $this->artisan('health:check');
        $this->artisan('health:check');
        $this->assertSame(1, InboxNotification::where('notifiable_id', $this->admin->id)->where('kind', 'system')->count());

        $this->artisan('health:backup-ok')->assertSuccessful();
        $this->actingAs($this->admin)->get(route('admin.health'))
            ->assertInertia(fn (Assert $page) => $page->where('checks', fn ($c) => collect($c)->firstWhere('key', 'backup')['status'] === 'ok'));

        $member = User::factory()->withRole('member')->create();
        $this->actingAs($member)->get(route('admin.health'))->assertForbidden();
    }

    public function test_admins_see_and_revoke_anyones_api_token()
    {
        $owner = User::factory()->withRole('member')->create();
        $plain = $owner->createToken('Zapier', ['read'])->plainTextToken;
        $this->getJson(route('api.v1.me'), ['Authorization' => 'Bearer '.$plain])->assertOk();

        $id = $owner->tokens()->firstOrFail()->id;
        $this->actingAs($this->admin)->get(route('admin.api-tokens.index'))
            ->assertInertia(fn (Assert $page) => $page->where('tokens.0.name', 'Zapier')->where('tokens.0.owner', $owner->name));
        $this->actingAs($this->admin)->delete(route('admin.api-tokens.destroy', $id))->assertRedirect();

        $this->app['auth']->forgetGuards();
        $this->getJson(route('api.v1.me'), ['Authorization' => 'Bearer '.$plain])->assertUnauthorized();
    }
}
