<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\BillingReports;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\WorkReport;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\RateCard;
use App\Models\Task;
use App\Models\TaskStatus;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * P6b: PDF invoice (stored at finalize), PDF work report, billing reports.
 * "Now" = 2026-11-03; October period; no VAT.
 *   Max dev  10-05 90 min @ 1,200/h (cost 500/h)  -> 1,800.00  (project "Website")
 *   Max seo  10-20 45 min @ 1,200/h (cost 500/h)  ->   900.00  (SEO task with keyword)
 *   Lena seo 10-21 30 min (approved, billable, no project) @ 1,200/h, no cost rate
 *   Unbilled after finalize: Max dev 11-01 60 min @ 1,200/h = 1,200.00
 */
class BillingDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $finance;

    private User $max;

    private User $lena;

    private BillingPeriod $october;

    private Task $seoTask;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00'));

        $this->ws = Workspace::factory()->create(['name' => 'Brewhouse']);
        $this->finance = User::factory()->withRole('finance')->create();
        $this->max = User::factory()->withRole('member')->create(['name' => 'Max']);
        $this->lena = User::factory()->withRole('member')->create(['name' => 'Lena']);
        $this->ws->members()->attach([$this->max->id => ['role' => Workspace::ROLE_MEMBER], $this->lena->id => ['role' => Workspace::ROLE_MEMBER]]);

        $dev = (int) Department::where('slug', 'development')->value('id');
        $seo = (int) Department::where('slug', 'seo')->value('id');
        $project = Project::factory()->create(['workspace_id' => $this->ws->id, 'name' => 'Website']);

        $devTask = Task::factory()->create(['workspace_id' => $this->ws->id, 'department_id' => $dev, 'title' => 'Build menu page', 'project_id' => $project->id]);
        $this->seoTask = Task::factory()->create([
            'workspace_id' => $this->ws->id, 'department_id' => $seo, 'title' => 'Optimise menu page',
            'work_details' => ['target_url' => 'https://brewhouse.ph/menu', 'keyword' => 'best coffee manila', 'work_type' => 'On-page'],
            'status_id' => TaskStatus::idFor('done'), 'completed_at' => '2026-10-22 10:00:00',
        ]);
        Task::factory()->create(['workspace_id' => $this->ws->id, 'department_id' => $dev, 'title' => 'Fix favicon',
            'status_id' => TaskStatus::idFor('done'), 'completed_at' => '2026-10-15 10:00:00']);

        BillingProfile::create(['workspace_id' => $this->ws->id, 'bill_to' => ['name' => 'Brewhouse Corp.'], 'payment_instructions' => 'GCash 0917 000 0000']);
        RateCard::create(['workspace_id' => null, 'user_id' => $this->max->id, 'rate_minor' => 120000, 'cost_minor' => 50000, 'effective_from' => '2026-01-01']);
        RateCard::create(['workspace_id' => null, 'rate_minor' => 120000, 'effective_from' => '2026-01-01']);

        $this->entry($this->max, $devTask, '2026-10-05', 90, $project->id);
        $this->entry($this->max, $this->seoTask, '2026-10-20', 45);
        $this->entry($this->lena, $this->seoTask, '2026-10-21', 30);
        $this->entry($this->max, $devTask, '2026-11-01', 60, $project->id);

        $this->october = BillingPeriod::create(['workspace_id' => $this->ws->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    }

    private function entry(User $user, Task $task, string $date, int $minutes, ?int $projectId = null): void
    {
        $e = TimeEntry::create([
            'user_id' => $user->id, 'workspace_id' => $this->ws->id, 'task_id' => $task->id, 'project_id' => $projectId,
            'department_id' => $task->department_id, 'entry_date' => $date, 'minutes' => $minutes, 'is_billable' => true,
        ]);
        $e->forceFill(['approved_at' => now()])->save();
    }

    private function finalized(): Invoice
    {
        $service = app(InvoiceService::class);

        return $service->finalize($service->buildDraft($this->october, $this->finance), $this->finance)->refresh();
    }

    public function test_finalize_stores_the_pdf_and_downloads_serve_that_copy()
    {
        $invoice = $this->finalized();

        $this->assertSame('invoices/'.$this->ws->id.'/INV-0001.pdf', $invoice->pdf_path);
        Storage::disk('local')->assertExists($invoice->pdf_path);
        $stored = Storage::disk('local')->get($invoice->pdf_path);
        $this->assertStringStartsWith('%PDF', (string) $stored);

        $response = $this->actingAs($this->finance)->get(route('billing.invoices.pdf', $invoice));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame($stored, $response->getContent());

        $this->actingAs($this->max)->get(route('billing.invoices.pdf', $invoice))->assertForbidden();
        $this->actingAs($this->max)->get(route('billing.invoices.work-report', $invoice))->assertForbidden();
    }

    public function test_drafts_preview_without_being_stored()
    {
        $draft = app(InvoiceService::class)->buildDraft($this->october, $this->finance);

        $response = $this->actingAs($this->finance)->get(route('billing.invoices.pdf', $draft));
        $response->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
        $this->assertNull($draft->fresh()->pdf_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_work_report_hours_equal_invoice_hours_and_sections_follow_the_data()
    {
        $invoice = $this->finalized();
        $report = app(WorkReport::class)->build($invoice);

        $invoiceMinutes = (int) $invoice->items()->where('type', 'hours')->sum('minutes');
        $this->assertSame(165, $invoiceMinutes);
        $this->assertSame($invoiceMinutes, $report['total_minutes']);

        $seo = collect($report['departments'])->firstWhere('name', 'SEO');
        $this->assertSame(75, $seo['minutes']);
        $this->assertSame([['name' => 'Max', 'minutes' => 45], ['name' => 'Lena', 'minutes' => 30]], $seo['people']);

        $this->assertSame([], $report['marketing']);
        $this->assertCount(1, $report['seo']);
        $this->assertSame('best coffee manila', $report['seo'][0]['keyword']);
        $this->assertSame('On-page', $report['seo'][0]['work_type']);
        $this->assertSame(75, $report['seo'][0]['minutes']);

        $this->assertSame(['Fix favicon'], array_column($report['completed_without_time'], 'title'));

        $pdf = $this->actingAs($this->finance)->get(route('billing.invoices.work-report', $invoice));
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
    }

    public function test_billing_reports_tie_to_invoices()
    {
        $invoice = $this->finalized();
        $this->assertSame(330000, $invoice->total_minor);

        $reports = app(BillingReports::class);
        $from = CarbonImmutable::parse('2026-11-01');
        $to = CarbonImmutable::parse('2026-11-30');

        $revenue = $reports->revenue($from, $to);
        $this->assertCount(1, $revenue);
        $this->assertSame(330000, $revenue[0]['net_minor']);

        $profit = collect($reports->profitability($from, $to))->keyBy('project');
        $this->assertSame(['minutes' => 90, 'revenue_minor' => 180000, 'cost_minor' => 75000, 'margin_minor' => 105000],
            collect($profit['Website'])->only(['minutes', 'revenue_minor', 'cost_minor', 'margin_minor'])->all());
        // No-project time: Max 45 min costed (375.00), Lena 30 min has no cost rate.
        $this->assertSame(150000, $profit['No project']['revenue_minor']);
        $this->assertSame(37500, $profit['No project']['cost_minor']);
        $this->assertSame(30, $profit['No project']['uncosted_minutes']);
        $this->assertSame(330000, collect($reports->profitability($from, $to))->sum('revenue_minor'));

        $unbilled = $reports->unbilled();
        $this->assertSame([['client' => 'Brewhouse', 'currency' => 'PHP', 'minutes' => 60, 'value_minor' => 120000, 'unpriced_minutes' => 0, 'oldest' => '2026-11-01']], $unbilled);

        $this->actingAs($this->finance)->get(route('billing.reports.index', ['from' => '2026-11', 'to' => '2026-11']))
            ->assertInertia(fn ($page) => $page->component('billing/Reports')->where('revenue.0.net_minor', 330000));
        $this->actingAs($this->max)->get(route('billing.reports.index'))->assertForbidden();
    }

    public function test_retainer_utilisation()
    {
        BillingProfile::where('workspace_id', $this->ws->id)->update(['retainer_minor' => 1000000, 'included_minutes' => 120]);

        $rows = app(BillingReports::class)->retainerUtilisation(CarbonImmutable::parse('2026-10-01'));

        $this->assertSame([['client' => 'Brewhouse', 'included_minutes' => 120, 'used_minutes' => 165, 'utilisation_pct' => 138]], $rows);
    }
}
