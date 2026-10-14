<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\BillingReports;
use App\Domain\Billing\CostRates;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\Money;
use App\Domain\Identity\PersonalData;
use App\Domain\People\PeopleReport;
use App\Models\ActivityLog;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PrepaidHours;
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
 * Enhancement batch E10b: realistic margins (E-77) and billing record retention (E-86).
 */
class EnhancementBatchE10bTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $ana;

    private User $ben;

    private Workspace $ws;

    private CarbonImmutable $from;

    private CarbonImmutable $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-31 12:00:00'));
        $this->from = CarbonImmutable::parse('2026-10-01');
        $this->to = CarbonImmutable::parse('2026-10-31');
        $this->finance = User::factory()->withRole('finance')->create(['name' => 'Grace Tan']);
        $this->ana = User::factory()->withRole('member')->create(['name' => 'Ana', 'primary_department_id' => Department::query()->value('id')]);
        $this->ben = User::factory()->withRole('member')->create(['name' => 'Ben']);
        $this->ws = Workspace::factory()->create(['name' => 'Manila Coffee']);
        RateCard::create(['rate_minor' => 200000, 'cost_minor' => 80000, 'effective_from' => '2026-01-01', 'currency' => 'PHP']); // ₱2,000/h, costs ₱800/h
    }

    private function log(Workspace $ws, User $who, string $date, int $minutes, ?int $projectId = null): void
    {
        TimeEntry::create(['user_id' => $who->id, 'workspace_id' => $ws->id, 'project_id' => $projectId, 'entry_date' => $date, 'minutes' => $minutes, 'is_billable' => true])
            ->forceFill(['approved_at' => now(), 'approved_by' => $this->finance->id])->save();
    }

    private function invoice(Workspace $ws): Invoice
    {
        $period = BillingPeriod::create(['workspace_id' => $ws->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $service = app(InvoiceService::class);

        return $service->finalize($service->buildDraft($period, $this->finance), $this->finance);
    }

    public function test_allocate_always_adds_up()
    {
        $this->assertSame([800000, 200000], Money::allocate(1000000, [240, 60]));
        $this->assertSame([34, 33, 33], Money::allocate(100, [1, 1, 1]));
        $this->assertSame([-34, -33, -33], Money::allocate(-100, [1, 1, 1]));
        $this->assertSame([7, 0], Money::allocate(7, [0, 0]));
        $this->assertSame([], Money::allocate(7, []));
    }

    public function test_retainer_revenue_is_shared_by_hours_and_ties_to_the_invoice()
    {
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'PHP', 'retainer_minor' => 1000000, 'included_minutes' => 300, 'tax_rate_bp' => 0]);
        $x = Project::factory()->create(['workspace_id' => $this->ws->id, 'name' => 'Website']);
        $y = Project::factory()->create(['workspace_id' => $this->ws->id, 'name' => 'Ads']);
        app(CostRates::class)->set($this->ana, 50000, '2026-01-01', null, $this->finance); // Ana costs ₱500/h
        $this->log($this->ws, $this->ana, '2026-10-05', 240, $x->id); // inside the retainer
        $this->log($this->ws, $this->ben, '2026-10-06', 120, $y->id); // 60 min inside, 60 min over at ₱2,000/h
        $invoice = $this->invoice($this->ws);
        $this->assertSame(1200000, $invoice->subtotal_minor);

        $reports = app(BillingReports::class);
        $byProject = collect($reports->profitability($this->from, $this->to))->keyBy('name');
        $this->assertSame(800000, $byProject['Website']['revenue_minor'], '240 of 300 retainer minutes');
        $this->assertSame(800000, $byProject['Website']['retainer_minor']);
        $this->assertSame(200000, $byProject['Website']['cost_minor'], 'cost table: 4 h at ₱500');
        $this->assertSame(400000, $byProject['Ads']['revenue_minor'], '60 retainer minutes + 1 h billed');
        $this->assertSame(160000, $byProject['Ads']['cost_minor'], 'rate card cost: 2 h at ₱800');

        foreach (['project', 'client', 'department', 'person'] as $by) {
            $this->assertSame(1200000, collect($reports->profitability($this->from, $this->to, $by))->sum('revenue_minor'), "ties out by {$by}");
        }
        $byPerson = collect($reports->profitability($this->from, $this->to, 'person'))->keyBy('name');
        $this->assertSame(800000, $byPerson['Ana']['revenue_minor']);
        $this->assertSame(22 * 480, $byPerson['Ana']['capacity_minutes'], '22 weekdays in October 2026');
        $this->assertSame(240, $byPerson['Ana']['billable_minutes']);
        $this->assertSame(2, $byPerson['Ana']['utilisation_pct']);

        $this->actingAs($this->finance)->get(route('billing.reports.index', ['from' => '2026-10', 'to' => '2026-10', 'by' => 'person']))
            ->assertInertia(fn (Assert $page) => $page->where('filters.by', 'person')->where('profitability.0.name', 'Ana'));
        $this->actingAs($this->finance)->get(route('billing.reports.download', ['report' => 'profitability', 'format' => 'csv', 'from' => '2026-10', 'to' => '2026-10', 'by' => 'client']))
            ->assertOk()->assertSee('Manila Coffee');

        // E-77: the people report uses the cost table too.
        $people = collect(app(PeopleReport::class)->month('2026-10')['rows'])->keyBy('name');
        $this->assertSame(200000, $people['Ana']['cost_minor']);
    }

    public function test_idle_retainers_and_prepaid_blocks_are_counted()
    {
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'PHP', 'retainer_minor' => 500000, 'included_minutes' => 600, 'tax_rate_bp' => 0]);
        $this->invoice($this->ws); // no hours this month

        $shop = Workspace::factory()->create(['name' => 'Bike Shop']);
        BillingProfile::create(['workspace_id' => $shop->id, 'currency' => 'PHP', 'tax_rate_bp' => 0]);
        PrepaidHours::create(['workspace_id' => $shop->id, 'kind' => 'block', 'minutes' => 120, 'amount_minor' => 300000, 'currency' => 'PHP', 'starts_on' => '2026-10-01']);
        $this->log($shop, $this->ana, '2026-10-07', 60);
        $this->invoice($shop);

        $rows = collect(app(BillingReports::class)->profitability($this->from, $this->to))->keyBy('client');
        $this->assertSame('Retainer – no hours used', $rows['Manila Coffee']['name']);
        $this->assertSame(500000, $rows['Manila Coffee']['revenue_minor']);
        $this->assertSame(150000, $rows['Bike Shop']['revenue_minor'], 'one hour of a ₱3,000 / 2 h block');
    }

    public function test_cost_rates_are_finance_only_and_effective_dated()
    {
        $this->actingAs($this->finance)->post(route('billing.cost-rates.store'), ['user_id' => $this->ana->id, 'cost' => '450', 'effective_from' => '2026-01-01'])->assertSessionHasNoErrors();
        $this->actingAs($this->finance)->post(route('billing.cost-rates.store'), ['user_id' => $this->ana->id, 'cost' => '500', 'effective_from' => '2026-10-01', 'note' => 'Raise'])->assertSessionHasNoErrors();
        $costs = app(CostRates::class);
        $this->assertSame(45000, $costs->perHour($this->ana->id, '2026-09-30'));
        $this->assertSame(50000, $costs->perHour($this->ana->id, '2026-10-01'));
        $this->assertNull($costs->perHour($this->ben->id, '2026-10-01'));
        $this->assertTrue(ActivityLog::query()->where('action', 'billing.cost-rate-set')->exists());

        $this->actingAs($this->finance)->get(route('billing.cost-rates.index'))->assertInertia(fn (Assert $page) => $page
            ->component('billing/CostRates')->where('canEdit', true)
            ->where('people', fn ($people) => collect($people)->firstWhere('name', 'Ana')['current_minor'] === 50000));
        $bookkeeper = User::factory()->withRole('bookkeeper')->create();
        $this->actingAs($bookkeeper)->get(route('billing.cost-rates.index'))->assertOk();
        $this->actingAs($bookkeeper)->post(route('billing.cost-rates.store'), ['user_id' => $this->ana->id, 'cost' => '1', 'effective_from' => '2026-10-01'])->assertForbidden();
        $this->actingAs($this->ana)->get(route('billing.cost-rates.index'))->assertForbidden();
    }

    public function test_billing_records_keep_names_and_survive_erasure()
    {
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'PHP', 'tax_rate_bp' => 0]);
        $this->log($this->ws, $this->ana, '2026-10-05', 60);
        $invoice = $this->invoice($this->ws);
        app(InvoiceService::class)->recordPayment($invoice, ['amount_minor' => 50000, 'method' => 'bank', 'received_on' => '2026-10-31'], $this->finance);
        $this->assertSame('Grace Tan', $invoice->fresh()?->finalized_by_name);

        $admin = User::factory()->withRole('admin')->create();
        $this->finance->forceFill(['is_active' => false])->save();
        $removed = app(PersonalData::class)->anonymise($this->finance, $admin);
        $this->assertGreaterThan(0, $removed['billing_records_kept']);
        $this->assertStringStartsWith('Former member', (string) $this->finance->fresh()?->name);

        $viewer = User::factory()->withRole('finance')->create();
        $this->actingAs($viewer)->get(route('billing.invoices.show', $invoice))->assertInertia(fn (Assert $page) => $page
            ->where('invoice.history', fn ($history) => collect($history)->every(fn ($h) => $h['by'] === null || $h['by'] === 'Grace Tan'))
            ->where('invoice.payments.0.by', 'Grace Tan'));
        $this->assertSame(1, Invoice::query()->whereKey($invoice->id)->count(), 'the invoice is untouched');

        $this->expectException(\LogicException::class);
        Payment::query()->firstOrFail()->delete();
    }

    public function test_restore_drill_finds_and_fixes_missing_pdfs()
    {
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'PHP', 'tax_rate_bp' => 0]);
        $this->log($this->ws, $this->ana, '2026-10-05', 60);
        $invoice = $this->invoice($this->ws)->fresh();
        $this->assertNotNull($invoice?->pdf_path);
        $this->artisan('billing:verify-pdfs')->assertSuccessful();

        Storage::disk('local')->delete((string) $invoice->pdf_path);
        $this->artisan('billing:verify-pdfs')->assertFailed();
        $this->artisan('billing:verify-pdfs', ['--fix' => true])->assertSuccessful();
        Storage::disk('local')->assertExists((string) $invoice->fresh()?->pdf_path);
    }
}
