<?php

namespace Tests\Feature\Performance;

use App\Domain\Files\AttachmentService;
use App\Domain\Reports\TimeAggregates;
use App\Domain\System\HealthChecks;
use App\Domain\Work\TimeService;
use App\Models\Attachment;
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
 * Enhancement batch E12b: pre-computed daily time totals that stay equal to the entries
 * (E-129), OPcache health row (E-115), cache-friendly thumbnail links (E-126).
 */
class EnhancementBatchE12bTest extends TestCase
{
    use RefreshDatabase;

    private User $ana;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:05'));
        $this->ana = User::factory()->withRole('member')->create(['name' => 'Ana']);
        $this->ws = Workspace::factory()->create(['name' => 'Alpha']);
    }

    /**
     * @return array{minutes: int, billable: int, approved: int}
     */
    private function daily(string $date): array
    {
        $row = DB::table('time_daily')->where('user_id', $this->ana->id)->whereDate('entry_date', $date)
            ->selectRaw('coalesce(sum(minutes), 0) as m, coalesce(sum(billable_minutes), 0) as b, coalesce(sum(approved_minutes), 0) as a')->first();

        return ['minutes' => (int) ($row->m ?? 0), 'billable' => (int) ($row->b ?? 0), 'approved' => (int) ($row->a ?? 0)];
    }

    private function entry(string $date, int $minutes, bool $billable = true): TimeEntry
    {
        return TimeEntry::create(['user_id' => $this->ana->id, 'workspace_id' => $this->ws->id, 'entry_date' => $date, 'minutes' => $minutes, 'is_billable' => $billable]);
    }

    public function test_daily_totals_follow_every_change_to_an_entry()
    {
        $a = $this->entry('2026-10-05', 90);
        $this->entry('2026-10-05', 30, false);
        $this->assertSame(['minutes' => 120, 'billable' => 90, 'approved' => 0], $this->daily('2026-10-05'));

        $a->update(['minutes' => 60]);
        $this->assertSame(60, $this->daily('2026-10-05')['billable']);

        $a->update(['entry_date' => '2026-10-06']);
        $this->assertSame(30, $this->daily('2026-10-05')['minutes'], 'the old day loses it');
        $this->assertSame(60, $this->daily('2026-10-06')['minutes'], 'the new day gains it');

        $approver = User::factory()->withRole('admin')->create();
        app(TimeService::class)->approve([$a->id], $approver);
        $this->assertSame(60, $this->daily('2026-10-06')['approved'], 'bulk approval refreshes the totals');

        $a->delete();
        $this->assertSame(0, $this->daily('2026-10-06')['minutes']);
        $a->restore();
        $this->assertSame(60, $this->daily('2026-10-06')['minutes']);

        // A running timer doesn't count until it stops.
        TimeEntry::create(['user_id' => $this->ana->id, 'workspace_id' => $this->ws->id, 'entry_date' => '2026-10-07', 'minutes' => 0,
            'is_billable' => true, 'started_at' => now()]);
        $this->assertSame(0, $this->daily('2026-10-07')['minutes']);
        $this->assertSame([], app(TimeAggregates::class)->drift(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')));
    }

    public function test_the_nightly_check_repairs_drift_and_reports_read_the_totals()
    {
        $this->entry('2026-10-05', 120);
        // A bulk insert that skips model events (e.g. an import) leaves the totals behind.
        DB::table('time_entries')->insert(['user_id' => $this->ana->id, 'workspace_id' => $this->ws->id, 'entry_date' => '2026-10-06',
            'minutes' => 45, 'is_billable' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertCount(1, app(TimeAggregates::class)->drift(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')));

        $admin = User::factory()->withRole('admin')->create();
        $range = ['report' => 'time', 'group' => 'person', 'from' => '2026-10-01', 'to' => '2026-10-07'];
        $this->actingAs($admin)->get(route('reports.index', $range))
            ->assertInertia(fn (Assert $page) => $page->where('result.rows.0.minutes', 120));

        $this->artisan('reports:aggregate', ['--days' => 30])->assertSuccessful();
        $this->assertSame([], app(TimeAggregates::class)->drift(CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31')));
        $this->actingAs($admin)->get(route('reports.index', $range))
            ->assertInertia(fn (Assert $page) => $page->where('result.rows.0.minutes', 165));

        DB::table('time_daily')->delete();
        $this->artisan('reports:aggregate', ['--all' => true])->assertSuccessful();
        $this->assertSame(165, (int) DB::table('time_daily')->sum('minutes'));
    }

    public function test_health_shows_opcache_without_failing_outside_production()
    {
        $row = collect(app(HealthChecks::class)->run())->firstWhere('key', 'opcache');
        $this->assertNotNull($row);
        $this->assertSame('ok', $row['status']);
    }

    public function test_thumbnail_links_stay_the_same_for_ten_minutes_so_browsers_cache_them()
    {
        $attachment = (new Attachment)->forceFill(['id' => 5]);
        $files = app(AttachmentService::class);

        $first = $files->url($attachment, thumbnail: true);
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:04:30'));
        $this->assertSame($first, $files->url($attachment, thumbnail: true), 'same 10-minute window, same URL');
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:11:00'));
        $this->assertNotSame($first, $files->url($attachment, thumbnail: true));
        $this->assertNotSame($files->url($attachment), $files->url($attachment, thumbnail: true));
    }
}
