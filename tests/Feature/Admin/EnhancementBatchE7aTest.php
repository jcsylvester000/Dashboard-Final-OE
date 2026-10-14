<?php

namespace Tests\Feature\Admin;

use App\Domain\Identity\SessionTracker;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\User;
use App\Models\UserSession;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Enhancement batch E7a: two-factor required for sensitive roles (E-87), signed-in devices
 * and sign-in history (E-88), split permissions and custom roles (E-89).
 */
class EnhancementBatchE7aTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo('2026-11-03 09:00:00');
    }

    public function test_sensitive_roles_get_a_grace_period_then_only_reach_the_security_page()
    {
        config(['security.enforce_two_factor' => true, 'security.two_factor_grace_days' => 7]);
        $finance = User::factory()->withRole('finance')->create();
        $member = User::factory()->withRole('member')->create();

        $this->actingAs($finance)->get(route('billing.invoices.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.twoFactorDue', fn ($d) => str_starts_with((string) $d, '2026-11-10')));

        $this->travelTo('2026-11-11 09:00:00');
        $this->actingAs($finance)->get(route('billing.invoices.index'))->assertRedirect(route('security.edit'));
        $this->actingAs($finance)->get(route('dashboard'))->assertRedirect(route('security.edit'));
        $this->actingAs($finance)->post(route('logout'))->assertRedirect();
        $this->actingAs($member)->get(route('dashboard'))->assertOk(); // members aren't asked

        // An authenticator app (or a passkey) lifts it.
        $finance->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
        $this->actingAs($finance)->get(route('billing.invoices.index'))->assertOk();
        $this->assertNull($finance->fresh()?->two_factor_deadline);

        $admin = User::factory()->withRole('admin')->create(['two_factor_deadline' => now()->subDay()]);
        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('security.edit'));
        DB::table('passkeys')->insert(['user_id' => $admin->id, 'name' => 'YubiKey', 'credential_id' => 'abc', 'credential' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
    }

    public function test_people_see_their_devices_and_can_end_one_or_all_others()
    {
        $user = User::factory()->withRole('member')->create();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();
        $this->assertSame(1, UserSession::query()->where('user_id', $user->id)->count());

        $other = UserSession::create(['user_id' => $user->id, 'session_id' => Str::random(40), 'ip_address' => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) Version/18.0 Mobile/15E148 Safari/604.1', 'last_seen_at' => now()]);
        $this->assertSame('Safari on iOS', SessionTracker::describe($other->user_agent));

        $this->actingAs($user)->delete(route('sessions.destroy', $other))->assertRedirect();
        $this->assertNotNull($other->fresh()?->revoked_at);

        // A request still carrying the ended session is signed out.
        $store = app('session')->driver();
        $store->setId($other->session_id);
        $request = Request::create('/dashboard');
        $request->setLaravelSession($store);
        $this->assertFalse(app(SessionTracker::class)->touch($request, $user));

        // Someone else's session can't be ended from my page.
        $stranger = UserSession::create(['user_id' => User::factory()->create()->id, 'session_id' => Str::random(40), 'last_seen_at' => now()]);
        $this->actingAs($user)->delete(route('sessions.destroy', $stranger))->assertNotFound();

        UserSession::create(['user_id' => $user->id, 'session_id' => Str::random(40), 'last_seen_at' => now()]);
        $this->actingAs($user)->post(route('sessions.destroy-others'))->assertRedirect();
        $this->assertSame(1, UserSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->count(), 'only the current one is left');
    }

    public function test_sign_in_history_marks_bursts_of_failures_and_admins_see_it()
    {
        $user = User::factory()->withRole('member')->create(['email' => 'bea@overeasy.test']);
        $admin = User::factory()->withRole('admin')->create();
        foreach (range(1, 5) as $i) {
            ActivityLog::query()->insert(['action' => 'auth.failed', 'properties' => json_encode(['email' => 'bea@overeasy.test']), 'ip_address' => '198.51.100.7', 'created_at' => now()->subMinutes(10 - $i)]);
        }
        ActivityLog::query()->insert(['action' => 'auth.login', 'subject_type' => $user->getMorphClass(), 'subject_id' => $user->id, 'actor_id' => $user->id, 'ip_address' => '203.0.113.9', 'created_at' => now()]);
        ActivityLog::query()->insert(['action' => 'auth.failed', 'properties' => json_encode(['email' => 'someone@else.test']), 'created_at' => now()]);

        $history = app(SessionTracker::class)->history($user);
        $this->assertCount(6, $history);
        $this->assertTrue($history[0]['ok']);
        $this->assertTrue($history[1]['burst']);

        $this->actingAs($admin)->get(route('admin.users.edit', $user))
            ->assertInertia(fn (Assert $page) => $page->has('loginHistory', 6)->has('devices'));

        // "Sign out everywhere" ends tracked sessions too (they live in Redis in production).
        UserSession::create(['user_id' => $user->id, 'session_id' => Str::random(40), 'last_seen_at' => now()]);
        $this->actingAs($admin)->post(route('admin.users.sign-out', $user))->assertRedirect();
        $this->assertSame(0, UserSession::query()->where('user_id', $user->id)->whereNull('revoked_at')->count());
    }

    public function test_a_bookkeeper_records_payments_but_cannot_finalize_or_prepare_invoices()
    {
        $bookkeeper = User::factory()->withRole('bookkeeper')->create();
        $finance = User::factory()->withRole('finance')->create();
        $ws = Workspace::factory()->create();
        $invoice = Invoice::create(['workspace_id' => $ws->id, 'series' => 'INV', 'currency' => 'PHP', 'tax_rate_bp' => 0]);
        $invoice->items()->create(['type' => 'adjustment', 'description' => 'Work', 'quantity' => '1.00', 'unit_minor' => 100000, 'amount_minor' => 100000, 'position' => 0]);
        $invoice->forceFill(['subtotal_minor' => 100000, 'total_minor' => 100000])->save();

        $this->actingAs($bookkeeper)->get(route('billing.invoices.index'))->assertOk();
        $this->actingAs($bookkeeper)->post(route('billing.invoices.finalize', $invoice))->assertForbidden();
        $this->actingAs($bookkeeper)->post(route('billing.invoices.lines.store', $invoice), ['description' => 'x', 'quantity' => '1', 'unit_price' => '1'])->assertForbidden();
        $this->actingAs($bookkeeper)->post(route('billing.rates.store'), ['rate' => '50', 'effective_from' => '2026-01-01'])->assertForbidden();

        $this->actingAs($finance)->post(route('billing.invoices.finalize', $invoice))->assertSessionHasNoErrors(); // billing.manage includes it
        $this->actingAs($bookkeeper)->post(route('billing.invoices.payments.store', $invoice), ['amount' => '1000', 'method' => 'bank', 'received_on' => '2026-11-03'])
            ->assertSessionHasNoErrors();
        $this->assertSame(Invoice::PAID, $invoice->fresh()?->status);

        $this->actingAs($bookkeeper)->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.can', fn ($can) => collect($can)->get('billing.payments') === true && collect($can)->get('billing.finalize') === false));
    }

    public function test_roles_can_be_created_copied_and_deleted_safely()
    {
        $super = User::factory()->withRole('super-admin')->create();
        $this->actingAs($super)->post(route('admin.roles.store'), ['label' => 'Collections', 'copy_from' => Role::findByName('bookkeeper')->id])->assertSessionHasNoErrors();
        $collections = Role::findByName('collections');
        $this->assertTrue($collections->hasPermissionTo('billing.payments'));
        $this->assertSame('Collections', $collections->getAttribute('label'));

        // A billing part brings billing.view with it.
        $this->actingAs($super)->put(route('admin.roles.update', $collections), ['permissions' => ['billing.payments']])->assertSessionHasNoErrors();
        $this->assertTrue($collections->fresh()?->hasPermissionTo('billing.view'));

        $holder = User::factory()->create();
        $holder->assignRole('collections');
        $this->actingAs($super)->delete(route('admin.roles.destroy', $collections))->assertSessionHasErrors('role');
        $this->actingAs($super)->delete(route('admin.roles.destroy', Role::findByName('finance')))->assertSessionHasErrors('role');
        $holder->syncRoles(['member']);
        $this->actingAs($super)->delete(route('admin.roles.destroy', $collections))->assertSessionHasNoErrors();
        $this->assertNull(Role::query()->where('name', 'collections')->first());
    }

    public function test_nobody_removes_their_own_access_or_grants_what_they_lack()
    {
        $role = Role::create(['name' => 'people-admin', 'guard_name' => 'web']);
        $role->syncPermissions(['roles.manage', 'users.view']);
        $me = User::factory()->create();
        $me->assignRole('people-admin');

        $this->actingAs($me)->put(route('admin.roles.update', $role), ['permissions' => ['users.view']])->assertSessionHasErrors('permissions');
        $this->actingAs($me)->put(route('admin.roles.update', $role), ['permissions' => ['roles.manage', 'users.view', 'billing.finalize']])->assertForbidden();
        $this->actingAs($me)->put(route('admin.roles.update', $role), ['permissions' => ['roles.manage']])->assertSessionHasNoErrors();
    }
}
