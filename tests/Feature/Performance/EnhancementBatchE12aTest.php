<?php

namespace Tests\Feature\Performance;

use App\Domain\Billing\InvoicePdf;
use App\Domain\Billing\InvoiceService;
use App\Domain\People\Capacity;
use App\Domain\Support\ReferenceData;
use App\Jobs\StoreInvoicePdf;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\Department;
use App\Models\Holiday;
use App\Models\RateCard;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enhancement batch E12a: hot-path indexes (E-117), cached reference data with
 * invalidation (E-119), capped report screens (E-122), PDFs rendered on a queue (E-128).
 */
class EnhancementBatchE12aTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00'));
    }

    public function test_hot_queries_have_their_indexes()
    {
        foreach ([
            ['invoice_items', ['invoice_id']], ['invoice_items', ['prepaid_hours_id']], ['invoice_item_sources', ['invoice_item_id']],
            ['invoice_history', ['invoice_id']], ['payments', ['invoice_id']], ['time_entries', ['project_id', 'entry_date']],
            ['tasks', ['parent_id']], ['expenses', ['project_id']],
        ] as [$table, $columns]) {
            $this->assertTrue(Schema::hasIndex($table, $columns), "{$table}(".implode(', ', $columns).') is indexed');
        }
    }

    public function test_department_list_is_cached_and_cleared_on_change()
    {
        $before = ReferenceData::departments();
        $this->assertTrue(Cache::has(ReferenceData::DEPARTMENTS_KEY));

        $new = Department::query()->create(['name' => 'Video', 'slug' => 'video', 'color' => 'violet', 'position' => 99]);
        $this->assertFalse(Cache::has(ReferenceData::DEPARTMENTS_KEY), 'creating a department clears the cache');
        $this->assertCount(count($before) + 1, ReferenceData::departments());

        $new->forceFill(['archived_at' => now()])->save();
        $this->assertSame(count($before), count(ReferenceData::departments()), 'archived departments drop out at once');
    }

    public function test_days_off_are_cached_per_year_and_cleared_on_change()
    {
        $capacity = app(Capacity::class);
        $user = User::factory()->withRole('member')->create();
        $full = $capacity->load('2026-12-01', '2026-12-31', [$user->id])->minutes($user->id);
        $this->assertTrue(Cache::has(ReferenceData::holidayKey(2026)));

        Holiday::query()->create(['date' => '2026-12-08', 'name' => 'Feast of the Immaculate Conception', 'kind' => 'special']);
        $this->assertFalse(Cache::has(ReferenceData::holidayKey(2026)));
        $this->assertSame($full - Capacity::DAY_MINUTES, $capacity->load('2026-12-01', '2026-12-31', [$user->id])->minutes($user->id));

        // Moving a holiday into another year clears both years.
        ReferenceData::daysOff(2027);
        Holiday::query()->firstOrFail()->update(['date' => '2027-01-02']);
        $this->assertFalse(Cache::has(ReferenceData::holidayKey(2026)));
        $this->assertFalse(Cache::has(ReferenceData::holidayKey(2027)));
    }

    public function test_reports_cap_the_rows_on_screen_but_say_how_many_there_are()
    {
        config(['reports.screen_rows' => 2]);
        $admin = User::factory()->withRole('admin')->create();
        $this->actingAs($admin)->get(route('reports.index', ['report' => 'throughput', 'from' => '2026-08-01', 'to' => '2026-10-07']))
            ->assertInertia(fn (Assert $page) => $page->where('result.rows', fn ($rows) => count($rows) === 2)
                ->where('rowsTotal', fn ($n) => $n > 2));
    }

    public function test_finalizing_queues_the_pdf_instead_of_rendering_it()
    {
        Storage::fake('local');
        Queue::fake();
        $finance = User::factory()->withRole('finance')->create();
        $member = User::factory()->withRole('member')->create();
        $ws = Workspace::factory()->create();
        BillingProfile::create(['workspace_id' => $ws->id, 'currency' => 'PHP', 'tax_rate_bp' => 0]);
        RateCard::create(['rate_minor' => 100000, 'cost_minor' => 40000, 'effective_from' => '2026-01-01', 'currency' => 'PHP']);
        TimeEntry::create(['user_id' => $member->id, 'workspace_id' => $ws->id, 'entry_date' => '2026-09-10', 'minutes' => 60, 'is_billable' => true])
            ->forceFill(['approved_at' => now(), 'approved_by' => $finance->id])->save();
        $period = BillingPeriod::create(['workspace_id' => $ws->id, 'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30']);
        $service = app(InvoiceService::class);
        $invoice = $service->finalize($service->buildDraft($period, $finance), $finance);

        $this->assertNull($invoice->fresh()?->pdf_path, 'nothing rendered while finalizing');
        Queue::assertPushedOn('pdf', StoreInvoicePdf::class, fn (StoreInvoicePdf $job) => $job->invoiceId === $invoice->id);

        (new StoreInvoicePdf($invoice->id))->handle(app(InvoicePdf::class));
        Storage::disk('local')->assertExists((string) $invoice->fresh()?->pdf_path);
    }
}
