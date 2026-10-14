<?php

namespace Tests\Feature\Admin;

use App\Domain\Billing\InvoiceService;
use App\Domain\Identity\ViewAs;
use App\Models\ActivityLog;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\RateCard;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enhancement batch E11a: view as a user, read-only (E-90), and the admin / Finance
 * tiles on the dashboard (E-98).
 */
class EnhancementBatchE11aTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    private User $member;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00'));
        $this->root = User::factory()->withRole('super-admin')->create(['last_login_at' => now()]);
        $this->member = User::factory()->withRole('member')->create(['name' => 'Mia', 'last_login_at' => now()]);
        $this->ws = Workspace::factory()->create();
        $this->ws->members()->attach($this->member->id, ['role' => Workspace::ROLE_MEMBER]);
    }

    public function test_a_super_admin_sees_the_app_as_the_person_and_cannot_change_anything()
    {
        $this->actingAs($this->root)->post(route('admin.users.view-as', $this->member), ['reason' => 'Mia cannot see the project'])
            ->assertRedirect(route('dashboard'));
        $this->assertTrue(ActivityLog::query()->where('action', 'user.view-as-started')->where('subject_id', $this->member->id)->exists());

        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('auth.user.id', $this->member->id)
            ->where('viewAs.name', 'Mia')
            ->where('viewAs.reason', 'Mia cannot see the project'));
        // Pages are authorised as the person: Mia has no admin access.
        $this->get(route('admin.users.index'))->assertForbidden();

        // Every write is refused, and nothing is stored.
        $before = Workspace::query()->count();
        $this->from(route('dashboard'))->post(route('workspaces.store'), ['name' => 'Sneaky'])->assertSessionHasErrors('view_as');
        $this->postJson(route('workspaces.store'), ['name' => 'Sneaky'])->assertForbidden();
        $this->assertSame($before, Workspace::query()->count());

        // Viewing a page doesn't write to the person's account either.
        $project = Project::factory()->create(['workspace_id' => $this->ws->id]);
        $this->get(route('workspaces.projects.show', [$this->ws, $project]))->assertOk();
        $this->assertNull($this->member->fresh()?->last_workspace_id);
        $this->assertFalse(ViewAs::$readOnly, 'the guard is off between requests');

        $this->post(route('view-as.stop'))->assertRedirect(route('admin.users.edit', $this->member));
        $this->assertTrue(ActivityLog::query()->where('action', 'user.view-as-ended')->where('properties->how', 'stopped')->exists());
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->root->id)->where('viewAs', null));
    }

    public function test_view_as_ends_after_thirty_minutes()
    {
        $this->actingAs($this->root)->post(route('admin.users.view-as', $this->member), ['reason' => 'Checking the board']);
        $this->travel(31)->minutes();
        $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $this->root->id));
        $this->assertTrue(ActivityLog::query()->where('action', 'user.view-as-ended')->where('properties->how', 'expired')->exists());
    }

    public function test_only_super_admins_and_never_on_another_super_admin()
    {
        $other = User::factory()->withRole('super-admin')->create();
        $this->actingAs($this->root)->post(route('admin.users.view-as', $other), ['reason' => 'Just looking'])->assertSessionHasErrors('view_as');
        $this->actingAs($this->root)->post(route('admin.users.view-as', $this->member), ['reason' => 'x'])->assertSessionHasErrors('reason');

        $admin = User::factory()->withRole('admin')->create();
        $this->actingAs($admin)->post(route('admin.users.view-as', $this->member), ['reason' => 'Just looking'])->assertSessionHasErrors('view_as');
        $this->actingAs($admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('auth.user.id', $admin->id));

        $this->actingAs($this->root)->get(route('admin.users.edit', $this->member))->assertInertia(fn (Assert $page) => $page->where('member.can_view_as', true));
        $this->actingAs($this->root)->get(route('admin.users.edit', $other))->assertInertia(fn (Assert $page) => $page->where('member.can_view_as', false));
    }

    public function test_admin_tiles_match_the_pages_they_link_to()
    {
        $admin = User::factory()->withRole('admin')->create(['last_login_at' => now()]);
        User::factory()->withRole('member')->create(['last_login_at' => null]);                 // never signed in
        User::factory()->withRole('member')->create(['must_change_password' => true, 'last_login_at' => now()]);
        User::factory()->withRole('member')->create(['last_login_at' => null, 'is_active' => false]); // inactive: not counted

        $tiles = null;
        $this->actingAs($admin)->get(route('dashboard'))->assertInertia(function (Assert $page) use (&$tiles) {
            $tiles = collect($page->toArray()['props']['roleHome']['admin'])->keyBy('key');
        });
        $this->assertSame(['pending-setup', 'approvals', 'failed-jobs', 'health'], $tiles?->keys()->all());
        $this->assertSame('2', $tiles['pending-setup']['value']);
        $this->actingAs($admin)->get($tiles['pending-setup']['href'])->assertInertia(fn (Assert $page) => $page->where('users.total', 2));

        // Members see no admin or Finance tiles.
        $this->actingAs($this->member)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
            ->where('roleHome.admin', [])->where('roleHome.finance', []));
    }

    public function test_finance_tiles_match_the_pages_they_link_to()
    {
        $finance = User::factory()->withRole('finance')->create(['last_login_at' => now()]);
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'PHP', 'tax_rate_bp' => 0, 'terms_days' => 15]);
        RateCard::create(['rate_minor' => 100000, 'cost_minor' => 40000, 'effective_from' => '2026-01-01', 'currency' => 'PHP']);
        TimeEntry::create(['user_id' => $this->member->id, 'workspace_id' => $this->ws->id, 'entry_date' => '2026-10-10', 'minutes' => 120, 'is_billable' => true])
            ->forceFill(['approved_at' => now(), 'approved_by' => $finance->id])->save();
        $this->travelTo(CarbonImmutable::parse('2026-10-31 10:00:00'));
        $period = BillingPeriod::create(['workspace_id' => $this->ws->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $invoice = app(InvoiceService::class)->finalize(app(InvoiceService::class)->buildDraft($period, $finance), $finance); // ₱2,000 due Nov 15
        $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00'));
        app(InvoiceService::class)->recordPayment($invoice, ['amount_minor' => 50000, 'method' => 'bank', 'received_on' => '2026-11-17'], $finance);
        TimeEntry::create(['user_id' => $this->member->id, 'workspace_id' => $this->ws->id, 'entry_date' => '2026-11-16', 'minutes' => 90, 'is_billable' => true])
            ->forceFill(['approved_at' => now(), 'approved_by' => $finance->id])->save();

        $tiles = null;
        $this->actingAs($finance)->get(route('dashboard'))->assertInertia(function (Assert $page) use (&$tiles) {
            $tiles = collect($page->toArray()['props']['roleHome']['finance'])->keyBy('key');
        });
        $this->assertSame('1.50 h', $tiles?->get('unbilled')['value']);
        $this->assertSame('1', $tiles['overdue']['value']);
        $this->assertSame('0', $tiles['review']['value']);
        $this->assertStringContainsString('500.00', $tiles['cash']['value']);

        $this->actingAs($finance)->get($tiles['overdue']['href'])->assertInertia(fn (Assert $page) => $page->where('invoices.total', 1));
        $this->actingAs($finance)->get(route('billing.invoices.index', ['status' => 'overdue']))
            ->assertInertia(fn (Assert $page) => $page->where('invoices.data.0.id', $invoice->id));
        $this->actingAs($finance)->get(route('billing.reports.index', ['from' => '2026-11', 'to' => '2026-11']))
            ->assertInertia(fn (Assert $page) => $page->where('cash.total_php_minor', 50000)
                ->where('unbilled', fn ($rows) => collect($rows)->sum('minutes') === 90));
        $this->assertSame(1, Invoice::query()->count());
    }
}
