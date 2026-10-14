<?php

namespace Tests\Feature\Reports;

use App\Domain\Exports\ExportTable;
use App\Domain\Exports\XlsxWriter;
use App\Models\ActivityLog;
use App\Models\BillingProfile;
use App\Models\Project;
use App\Models\RateCard;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enhancement batch E9b: project budgets with burn alerts (E-76) in the client's currency
 * (E-110), and Excel / PDF / CSV for every report (E-49) including billing reports (E-81).
 */
class EnhancementBatchE9bTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $lead;

    private User $member;

    private Workspace $ws;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00:00'));
        $this->finance = User::factory()->withRole('finance')->create();
        $this->lead = User::factory()->withRole('member')->create();
        $this->member = User::factory()->withRole('member')->create();
        $this->ws = Workspace::factory()->create(['name' => 'Berlin Bakery']);
        $this->ws->members()->attach([$this->lead->id => ['role' => Workspace::ROLE_LEAD], $this->member->id => ['role' => Workspace::ROLE_MEMBER]]);
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'USD', 'tax_rate_bp' => 0]);
        RateCard::create(['rate_minor' => 5000, 'cost_minor' => 2000, 'effective_from' => '2026-01-01', 'currency' => 'USD']); // $50/h
        $this->project = Project::factory()->create(['workspace_id' => $this->ws->id, 'name' => 'Spring launch', 'lead_user_id' => $this->lead->id, 'status' => 'active']);
    }

    private function log(int $minutes): void
    {
        TimeEntry::create(['user_id' => $this->member->id, 'workspace_id' => $this->ws->id, 'project_id' => $this->project->id,
            'entry_date' => '2026-11-05', 'minutes' => $minutes, 'is_billable' => true]);
    }

    private function budgetUrl(): string
    {
        return route('workspaces.projects.budget', [$this->ws, $this->project]);
    }

    public function test_leads_set_hours_finance_sets_the_amount_in_the_client_currency()
    {
        $this->actingAs($this->member)->put($this->budgetUrl(), ['budget_hours' => 10])->assertForbidden();

        // A lead's amount is ignored: amounts are Finance's.
        $this->actingAs($this->lead)->put($this->budgetUrl(), ['budget_hours' => 10, 'budget_amount' => '999'])->assertSessionHasNoErrors();
        $this->project->refresh();
        $this->assertSame(600, $this->project->budget_minutes);
        $this->assertNull($this->project->budget_minor);

        // Finance saving only the amount leaves the hours alone.
        $this->actingAs($this->finance)->put($this->budgetUrl(), ['budget_amount' => '500.00'])->assertSessionHasNoErrors();
        $this->project->refresh();
        $this->assertSame(600, $this->project->budget_minutes);
        $this->assertSame(50000, $this->project->budget_minor);
        $this->assertSame('USD', $this->project->budget_currency);
        $this->actingAs($this->finance)->put($this->budgetUrl(), ['budget_amount' => '1.234'])->assertSessionHasErrors('budget_amount');
        $this->assertTrue(ActivityLog::query()->where('action', 'project.budget-changed')->exists());

        $this->log(300); // 5 h = 50 %, $250 = 50 %
        $show = route('workspaces.projects.show', [$this->ws, $this->project]);
        $this->actingAs($this->lead)->get($show)->assertInertia(fn (Assert $page) => $page
            ->where('budget.minutes_used', 300)->where('budget.hours_pct', 50)->missing('budget.amount_used'));
        $this->actingAs($this->finance)->get($show)->assertInertia(fn (Assert $page) => $page
            ->where('budget.amount_used', 25000)->where('budget.amount_pct', 50)->where('budget.currency', 'USD'));
        $this->actingAs($this->member)->get($show)->assertInertia(fn (Assert $page) => $page->where('budget', null));

        $this->actingAs($this->lead)->get(route('workspaces.projects.index', $this->ws))
            ->assertInertia(fn (Assert $page) => $page->where('projects.data.0.budget_hours_pct', 50));
        $this->actingAs($this->member)->get(route('workspaces.projects.index', $this->ws))
            ->assertInertia(fn (Assert $page) => $page->where('projects.data.0.budget_hours_pct', null));
    }

    public function test_burn_alerts_go_out_once_per_step()
    {
        $this->project->forceFill(['budget_minutes' => 600, 'budget_minor' => 50000, 'budget_currency' => 'USD'])->save();

        $this->log(450); // 75 %
        $this->artisan('budgets:check')->assertSuccessful();
        $this->assertSame(1, $this->lead->notifications()->count(), 'hours alert to the lead');
        $this->assertSame(2, $this->finance->notifications()->count(), 'hours and amount to Finance');
        $this->assertSame(0, $this->member->notifications()->count(), 'members never hear about budgets');
        $this->assertStringContainsString('75%', (string) $this->lead->notifications()->first()?->data['title']);

        $this->artisan('budgets:check');
        $this->assertSame(1, $this->lead->notifications()->count(), 'the same step is never sent twice');

        $this->log(150); // jumps to 100 %: one alert, not two
        $this->artisan('budgets:check');
        $this->assertSame(2, $this->lead->notifications()->count());
        $this->assertSame(4, $this->finance->notifications()->count());
        $this->assertSame(100, $this->project->fresh()?->budget_hours_alerted_pct);

        // A new budget re-arms the alerts.
        $this->actingAs($this->lead)->put($this->budgetUrl(), ['budget_hours' => 20]);
        $this->assertSame(0, $this->project->fresh()?->budget_hours_alerted_pct);
    }

    public function test_xlsx_writer_formats_totals_and_neutralises_formulas()
    {
        $t = new ExportTable('Test report', ['Period' => 'Nov 2026'], [
            ['key' => 'name', 'label' => 'Name', 'type' => 'text'],
            ['key' => 'hours', 'label' => 'Hours', 'type' => 'hours'],
            ['key' => 'amount', 'label' => 'Amount', 'type' => 'money', 'currency' => 'PHP', 'sum' => true],
        ], [
            ['name' => '=HYPERLINK("http://x")', 'hours' => 1.5, 'amount' => 12345],
            ['name' => 'Ana & Bo <QA>', 'hours' => 2, 'amount' => 100],
        ]);
        $bytes = (new XlsxWriter)->make($t);

        $this->assertStringStartsWith('PK', $bytes);
        $this->assertStringContainsString('state="frozen"', $bytes);
        $this->assertStringContainsString('<f>SUM(B5:B6)</f>', $bytes);
        $this->assertStringContainsString('<f>SUM(C5:C6)</f>', $bytes);
        $this->assertStringContainsString('<v>123.45</v>', $bytes);
        // Text goes in as an inline string, which Excel never runs as a formula.
        $this->assertStringContainsString('<t xml:space="preserve">=HYPERLINK(&quot;http://x&quot;)</t>', $bytes);
        $this->assertStringNotContainsString('<f>HYPERLINK', $bytes);
        $this->assertStringContainsString('Ana &amp; Bo &lt;QA&gt;', $bytes);

        if (class_exists(\ZipArchive::class)) {
            $path = tempnam(sys_get_temp_dir(), 'xlsx');
            file_put_contents((string) $path, $bytes);
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open((string) $path) === true, 'a valid zip');
            $this->assertNotFalse($zip->getFromName('xl/worksheets/sheet1.xml'));
            $this->assertNotFalse(simplexml_load_string((string) $zip->getFromName('xl/styles.xml')), 'well-formed styles');
            $this->assertNotFalse(simplexml_load_string((string) $zip->getFromName('xl/worksheets/sheet1.xml')), 'well-formed sheet');
            $zip->close();
            @unlink((string) $path);
        }
    }

    public function test_every_report_downloads_as_excel_pdf_and_csv()
    {
        $this->project->forceFill(['budget_minutes' => 600])->save();
        $this->log(120);

        $xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
        $r = $this->actingAs($this->finance)->get(route('reports.download', ['format' => 'xlsx', 'report' => 'time', 'from' => '2026-11-01', 'to' => '2026-11-30']))->assertOk();
        $this->assertSame($xlsx, $r->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="time-logged-2026-11-10.xlsx"', (string) $r->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $this->actingAs($this->finance)->get(route('reports.download', ['format' => 'pdf', 'report' => 'throughput']))->assertOk()->getContent());
        $this->assertStringStartsWith("\xEF\xBB\xBF", (string) $this->actingAs($this->finance)->get(route('reports.download', ['format' => 'csv', 'report' => 'workload']))->assertOk()->getContent());
        $this->actingAs($this->member)->get(route('reports.download', ['format' => 'xlsx']))->assertForbidden();
        $this->actingAs($this->finance)->get('/reports/download/docx')->assertNotFound();

        // E-81: billing reports, each in each format.
        foreach (['revenue', 'utilisation', 'unbilled', 'profitability', 'aging', 'budgets'] as $report) {
            foreach (['xlsx', 'pdf', 'csv'] as $format) {
                $this->actingAs($this->finance)->get(route('billing.reports.download', ['report' => $report, 'format' => $format]))->assertOk();
            }
        }
        $budgets = (string) $this->actingAs($this->finance)->get(route('billing.reports.download', ['report' => 'budgets', 'format' => 'csv']))->getContent();
        $this->assertStringContainsString('Spring launch', $budgets);
        $this->assertStringContainsString(',10,2,20,', $budgets); // 2 h of 10 h = 20 %
        $this->actingAs($this->member)->get(route('billing.reports.download', ['report' => 'revenue', 'format' => 'xlsx']))->assertForbidden();

        $this->actingAs($this->finance)->get(route('reports.people.download', ['format' => 'xlsx', 'month' => '2026-11']))->assertOk()->assertHeader('Content-Type', $xlsx);
        $this->actingAs($this->lead)->get(route('workspaces.work-summary.xlsx', $this->ws))->assertOk()->assertHeader('Content-Type', $xlsx);
        $this->actingAs($this->member)->get(route('workspaces.work-summary.xlsx', $this->ws))->assertForbidden();

        $this->actingAs($this->finance)->get(route('billing.reports.index'))
            ->assertInertia(fn (Assert $page) => $page->where('budgets.0.project', 'Spring launch')->where('budgets.0.hours_pct', 20)->has('exports', 6));
    }
}
