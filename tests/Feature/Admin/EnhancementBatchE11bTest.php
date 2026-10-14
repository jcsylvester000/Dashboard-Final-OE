<?php

namespace Tests\Feature\Admin;

use App\Domain\Privacy\PrivacyProgram;
use App\Models\ActivityLog;
use App\Models\PrivacyAcknowledgement;
use App\Models\PrivacyIncident;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enhancement batch E11b (E-99): staff privacy notice acknowledgements, retention schedule,
 * DPO contact and the admin-only incident log.
 */
class EnhancementBatchE11bTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    private const NOTICE = 'We keep your name, work email and the time you log so we can run projects and bill clients.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->travelTo(CarbonImmutable::parse('2026-11-20 09:00:00'));
        $this->admin = User::factory()->withRole('admin')->create();
        $this->member = User::factory()->withRole('member')->create();
    }

    public function test_everyone_acknowledges_each_new_version_and_it_is_stored_with_the_date()
    {
        // Nothing to acknowledge before a notice is published.
        $this->actingAs($this->member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('privacy.notice', null));

        $this->actingAs($this->admin)->post(route('admin.privacy.notice'), ['title' => 'Staff privacy notice', 'body' => self::NOTICE])->assertSessionHasNoErrors();
        $this->actingAs($this->member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('privacy.notice.version', 1)->where('privacy.notice.body', self::NOTICE));

        $this->actingAs($this->member)->post(route('privacy.acknowledge'), ['version' => 1])->assertSessionHasNoErrors();
        $ack = PrivacyAcknowledgement::query()->where('user_id', $this->member->id)->firstOrFail();
        $this->assertSame(1, $ack->version);
        $this->assertSame('2026-11-20 09:00:00', $ack->acknowledged_at->format('Y-m-d H:i:s'));
        $this->actingAs($this->member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('privacy.notice', null));

        // Version 2 asks again; acknowledging an old version is refused.
        $this->actingAs($this->admin)->post(route('admin.privacy.notice'), ['title' => 'Staff privacy notice', 'body' => self::NOTICE.' Updated.']);
        $this->actingAs($this->member)->post(route('privacy.acknowledge'), ['version' => 1])->assertSessionHasErrors('version');
        $this->actingAs($this->member)->get(route('privacy.index'))->assertInertia(fn (Assert $page) => $page
            ->where('notice.version', 2)->where('acknowledgedAt', null));

        $this->actingAs($this->admin)->get(route('admin.privacy.index'))->assertInertia(fn (Assert $page) => $page
            ->where('acknowledgements.done', 0)->where('acknowledgements.total', 2));
        $this->assertTrue(ActivityLog::query()->where('action', 'privacy.notice-acknowledged')->exists());
        $this->assertTrue(ActivityLog::query()->where('action', 'privacy.notice-published')->where('properties->version', 2)->exists());
    }

    public function test_the_retention_schedule_lists_every_data_type_with_its_period()
    {
        $this->actingAs($this->member)->get(route('privacy.index'))->assertInertia(fn (Assert $page) => $page
            ->component('privacy/Index')
            ->where('retention', fn ($rows) => collect($rows)->count() >= 10
                && collect($rows)->every(fn ($r) => $r['data'] !== '' && $r['kept'] !== '')
                && collect($rows)->firstWhere('data', 'Billing records')['kept'] === '10 years'
                && collect($rows)->firstWhere('data', 'Sign-in history and devices')['kept'] === '180 days'));
    }

    public function test_old_sign_in_history_is_pruned()
    {
        foreach ([200, 10] as $days) {
            DB::table('user_sessions')->insert(['user_id' => $this->member->id, 'session_id' => Str::random(40), 'ip_address' => '203.0.113.5',
                'user_agent' => 'Test', 'last_seen_at' => now()->subDays($days), 'created_at' => now()->subDays($days)]);
        }
        $this->artisan('privacy:prune-logins')->assertSuccessful();
        $this->assertSame(1, DB::table('user_sessions')->count());
    }

    public function test_the_dpo_contact_shows_for_everyone()
    {
        $this->actingAs($this->admin)->put(route('admin.privacy.dpo'), ['dpo_name' => 'Rosa Cruz', 'dpo_email' => 'dpo@overeasy.local', 'dpo_phone' => ''])->assertSessionHasNoErrors();
        $this->actingAs($this->member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('privacy.dpo.name', 'Rosa Cruz')->where('privacy.dpo.email', 'dpo@overeasy.local')->where('privacy.dpo.phone', null));
        $this->actingAs($this->member)->put(route('admin.privacy.dpo'), ['dpo_name' => 'Me'])->assertForbidden();
    }

    public function test_the_incident_log_is_admin_only_and_every_change_is_logged()
    {
        $data = ['title' => 'Laptop lost on a jeepney', 'discovered_at' => '2026-11-19 18:00', 'occurred_at' => '2026-11-19 08:00',
            'description' => 'A work laptop with the shared drive synced was lost.', 'data_involved' => 'Staff names, client contact names',
            'people_affected' => 12, 'severity' => 'high', 'status' => 'open'];

        $this->actingAs($this->member)->get(route('admin.privacy.index'))->assertForbidden();
        $this->actingAs($this->member)->post(route('admin.privacy.incidents.store'), $data)->assertForbidden();

        $this->actingAs($this->admin)->post(route('admin.privacy.incidents.store'), $data)->assertSessionHasNoErrors();
        $incident = PrivacyIncident::query()->firstOrFail();
        $this->assertSame($this->admin->id, $incident->created_by);
        $log = ActivityLog::query()->where('action', 'privacy.incident-recorded')->firstOrFail();
        $this->assertSame(['severity' => 'high', 'status' => 'open'], $log->properties, 'no personal details in the audit row');

        $this->actingAs($this->admin)->put(route('admin.privacy.incidents.update', $incident), [...$data, 'status' => 'contained', 'npc_notified_at' => '2026-11-20 08:00'])
            ->assertSessionHasNoErrors();
        $this->assertSame('contained', $incident->fresh()?->status);
        $this->assertTrue(ActivityLog::query()->where('action', 'privacy.incident-updated')->exists());

        $this->actingAs($this->admin)->get(route('admin.privacy.index'))->assertInertia(fn (Assert $page) => $page
            ->where('incidents.0.title', 'Laptop lost on a jeepney')
            ->where('incidents.0.notify_by', fn ($at) => str_starts_with((string) $at, '2026-11-22T18:00')));

        $this->actingAs($this->admin)->post(route('admin.privacy.incidents.store'), [...$data, 'occurred_at' => '2026-11-20 08:00'])
            ->assertSessionHasErrors('occurred_at');
    }

    public function test_viewing_as_someone_never_prompts_or_acknowledges_for_them()
    {
        app(PrivacyProgram::class)->publish('Staff privacy notice', self::NOTICE, $this->admin);
        $root = User::factory()->withRole('super-admin')->create();
        app(PrivacyProgram::class)->acknowledge($root, 1);
        $this->actingAs($root)->post(route('admin.users.view-as', $this->member), ['reason' => 'Checking the notice']);
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->member->id)->where('privacy.notice', null));
        $this->post(route('privacy.acknowledge'), ['version' => 1])->assertSessionHasErrors('view_as');
        $this->assertFalse(PrivacyAcknowledgement::query()->where('user_id', $this->member->id)->exists());
    }
}
