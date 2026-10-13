<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\PeriodService;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\RateCard;
use App\Models\Task;
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
 * Finding S3-14: invoice one project or campaign on its own (e.g. only the Arizona campaign).
 * Rate 1,000/h everywhere; Arizona 120 min, Website 60 min, no project 30 min; retainer 5,000.
 */
class ProjectInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $finance;

    private Project $arizona;

    private Project $website;

    private BillingPeriod $october;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00'));

        $this->ws = Workspace::factory()->create(['name' => 'Desert Sun']);
        $this->finance = User::factory()->withRole('finance')->create();
        $dev = User::factory()->withRole('member')->create();
        $this->ws->members()->attach($dev->id, ['role' => Workspace::ROLE_MEMBER]);
        BillingProfile::create(['workspace_id' => $this->ws->id, 'tax_rate_bp' => 0, 'retainer_minor' => 500000]);
        RateCard::create(['rate_minor' => 100000, 'effective_from' => '2026-01-01']);

        $this->arizona = Project::factory()->create(['workspace_id' => $this->ws->id, 'name' => 'Arizona campaign']);
        $this->website = Project::factory()->create(['workspace_id' => $this->ws->id, 'name' => 'Website']);
        $this->log($dev, $this->arizona, 120);
        $this->log($dev, $this->website, 60);
        $this->log($dev, null, 30);
        $this->october = BillingPeriod::create(['workspace_id' => $this->ws->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    }

    private function log(User $user, ?Project $project, int $minutes): void
    {
        $task = Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $project?->id]);
        TimeEntry::create([
            'user_id' => $user->id, 'workspace_id' => $this->ws->id, 'task_id' => $task->id, 'project_id' => $project?->id,
            'entry_date' => '2026-10-10', 'minutes' => $minutes, 'is_billable' => true,
        ])->forceFill(['approved_at' => now(), 'approved_by' => $this->finance->id])->save();
    }

    public function test_a_project_invoice_bills_only_that_project_and_keeps_the_period_open()
    {
        $this->actingAs($this->finance)->post(route('billing.periods.invoice', $this->october), ['project_id' => $this->arizona->id])
            ->assertRedirect();

        $invoice = Invoice::query()->where('project_id', $this->arizona->id)->firstOrFail();
        $this->assertSame(200000, $invoice->subtotal_minor); // 2h x 1,000, no retainer
        $this->assertSame(['hours'], $invoice->items()->pluck('type')->unique()->values()->all());

        app(InvoiceService::class)->finalize($invoice, $this->finance);
        $this->assertSame(BillingPeriod::LOCKED, $this->october->fresh()->status);

        // The whole-client invoice afterwards: retainer + the rest, never Arizona again.
        $rest = app(InvoiceService::class)->buildDraft($this->october, $this->finance);
        $this->assertNull($rest->project_id);
        $this->assertSame(500000 + 150000, $rest->subtotal_minor); // retainer + 1.5h (no included hours)
    }

    public function test_one_finalized_invoice_per_project_and_unlock_is_blocked()
    {
        $service = app(InvoiceService::class);
        $service->finalize($service->buildDraft($this->october, $this->finance, null, $this->arizona), $this->finance);

        try {
            $service->buildDraft($this->october, $this->finance, null, $this->arizona);
            $this->fail('A second Arizona invoice was allowed.');
        } catch (BillingException $e) {
            $this->assertStringContainsString('already has a finalized invoice', $e->getMessage());
        }

        $website = $service->buildDraft($this->october, $this->finance, null, $this->website);
        $this->assertSame(100000, $website->subtotal_minor);

        $this->expectException(BillingException::class);
        app(PeriodService::class)->unlock($this->october->fresh());
    }

    public function test_projects_from_another_client_are_rejected()
    {
        $other = Project::factory()->create();

        $this->actingAs($this->finance)->post(route('billing.periods.invoice', $this->october), ['project_id' => $other->id])
            ->assertSessionHasErrors('project_id');
        $this->assertSame(0, Invoice::query()->count());
    }
}
