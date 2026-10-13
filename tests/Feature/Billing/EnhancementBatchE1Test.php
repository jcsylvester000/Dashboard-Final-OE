<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\Money;
use App\Models\ActivityLog;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\RateCard;
use App\Models\RateTemplate;
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
 * Enhancement batch E1: void keeps the period right (E-61), money audit trail (E-62),
 * missing rates don't block drafts (E-64), rate corrections (E-65), currency-aware rates
 * (E-100), EUR (E-101), safe currency change (E-111).
 */
class EnhancementBatchE1Test extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $finance;

    private User $dev;

    private BillingPeriod $october;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00'));

        $this->ws = Workspace::factory()->create(['name' => 'Berlin Bakery']);
        $this->finance = User::factory()->withRole('finance')->create();
        $this->dev = User::factory()->withRole('member')->create(['name' => 'Leo Bautista']);
        $this->ws->members()->attach($this->dev->id, ['role' => Workspace::ROLE_MEMBER]);
        RateCard::create(['rate_minor' => 150000, 'effective_from' => '2026-01-01']); // PHP 1,500 agency default
        $this->october = BillingPeriod::create(['workspace_id' => $this->ws->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    }

    private function log(int $minutes, ?Project $project = null): void
    {
        $task = Task::factory()->create(['workspace_id' => $this->ws->id, 'project_id' => $project?->id]);
        TimeEntry::create([
            'user_id' => $this->dev->id, 'workspace_id' => $this->ws->id, 'task_id' => $task->id, 'project_id' => $project?->id,
            'entry_date' => '2026-10-10', 'minutes' => $minutes, 'is_billable' => true,
        ])->forceFill(['approved_at' => now(), 'approved_by' => $this->finance->id])->save();
    }

    public function test_a_usd_client_never_falls_back_to_peso_rates()
    {
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'USD', 'tax_rate_bp' => 0]);
        $this->log(120);

        $draft = app(InvoiceService::class)->buildDraft($this->october, $this->finance);
        $this->assertSame(0, $draft->subtotal_minor, 'PHP 1,500 must not be billed as USD 1,500');
        $this->assertSame('Leo Bautista', $draft->missing_rates[0]['name'] ?? null);
        $this->assertSame(120, $draft->missing_rates[0]['minutes'] ?? null);

        // A USD agency default prices it.
        $this->actingAs($this->finance)->post(route('billing.rates.store'), ['rate' => '50', 'effective_from' => '2026-01-01', 'currency' => 'USD'])
            ->assertSessionHasNoErrors();
        $this->assertSame('USD', RateCard::query()->latest('id')->value('currency'));
        $draft = app(InvoiceService::class)->buildDraft($this->october->fresh() ?? $this->october, $this->finance);
        $this->assertSame(10000, $draft->subtotal_minor); // 2h x $50
        $this->assertNull($draft->missing_rates);
    }

    public function test_client_rates_take_the_client_currency_and_euro_works()
    {
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'EUR', 'tax_rate_bp' => 0]);
        $this->actingAs($this->finance)->post(route('billing.rates.store'), ['workspace_id' => $this->ws->id, 'rate' => '60', 'effective_from' => '2026-01-01', 'currency' => 'USD'])
            ->assertSessionHasNoErrors();
        $this->assertSame('EUR', RateCard::query()->where('workspace_id', $this->ws->id)->value('currency'), 'client cards ignore the posted currency');

        $this->log(90);
        $draft = app(InvoiceService::class)->buildDraft($this->october, $this->finance);
        $this->assertSame('EUR', $draft->currency);
        $this->assertSame(9000, $draft->subtotal_minor);
        $this->assertSame("\u{20AC}90.00", Money::format(9000, 'EUR'));

        // A PHP template can't be applied to a EUR client.
        $template = RateTemplate::create(['name' => 'Standard PHP', 'currency' => 'PHP']);
        $this->actingAs($this->finance)->post(route('billing.clients.rate-template', $this->ws), ['rate_template_id' => $template->id, 'effective_from' => '2026-11-01'])
            ->assertSessionHasErrors('billing');
    }

    public function test_missing_rates_block_finalize_only_until_fixed()
    {
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'PHP', 'tax_rate_bp' => 0]);
        RateCard::query()->delete();
        $this->log(60);

        $this->actingAs($this->finance)->post(route('billing.periods.invoice', $this->october))->assertSessionHasNoErrors();
        $draft = Invoice::query()->firstOrFail();
        $this->actingAs($this->finance)->get(route('billing.invoices.show', $draft))
            ->assertInertia(fn ($page) => $page->where('invoice.missing_rates.0.name', 'Leo Bautista'));
        $this->actingAs($this->finance)->post(route('billing.invoices.finalize', $draft))->assertSessionHasErrors('billing');

        RateCard::create(['rate_minor' => 100000, 'effective_from' => '2026-01-01']);
        $this->actingAs($this->finance)->post(route('billing.invoices.recalculate', $draft))->assertSessionHasNoErrors();
        $this->actingAs($this->finance)->post(route('billing.invoices.finalize', $draft))->assertSessionHasNoErrors();
        $this->assertSame(Invoice::FINALIZED, $draft->fresh()?->status);
    }

    public function test_voiding_a_project_invoice_keeps_an_invoiced_period_invoiced()
    {
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'PHP', 'tax_rate_bp' => 0]);
        $campaign = Project::factory()->create(['workspace_id' => $this->ws->id]);
        $this->log(60, $campaign);
        $this->log(30);
        $service = app(InvoiceService::class);

        $projectInvoice = $service->finalize($service->buildDraft($this->october, $this->finance, null, $campaign), $this->finance);
        $whole = $service->finalize($service->buildDraft($this->october->fresh() ?? $this->october, $this->finance), $this->finance);
        $this->assertSame(BillingPeriod::INVOICED, $this->october->fresh()?->status);

        $service->void($projectInvoice, $this->finance, 'Wrong campaign');
        $this->assertSame(BillingPeriod::INVOICED, $this->october->fresh()?->status, 'the whole-client invoice still covers the period');

        $service->void($whole->fresh() ?? $whole, $this->finance, 'Re-issue');
        $this->assertSame(BillingPeriod::LOCKED, $this->october->fresh()?->status);
    }

    public function test_money_actions_are_in_the_activity_log()
    {
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'PHP', 'tax_rate_bp' => 1200, 'bill_to' => ['name' => 'Berlin Bakery']]);
        $this->log(60);
        $service = app(InvoiceService::class);
        $invoice = $service->finalize($service->buildDraft($this->october, $this->finance), $this->finance);
        $this->actingAs($this->finance)->post(route('billing.invoices.payments.store', $invoice), ['amount' => '100', 'method' => 'gcash', 'received_on' => '2026-11-03']);

        $profile = ['currency' => 'PHP', 'cycle' => 'monthly', 'retainer' => '0', 'included_hours' => '0', 'overage_rule' => 'bill',
            'terms_days' => 30, 'tax_rate' => '12', 'invoice_series' => 'INV', 'bill_to' => ['name' => 'Berlin Bakery GmbH']];
        $this->actingAs($this->finance)->put(route('billing.clients.profile', $this->ws), $profile)->assertSessionHasNoErrors();
        $this->actingAs($this->finance)->post(route('billing.clients.expenses.store', $this->ws), ['description' => 'Stock photos', 'amount' => '500', 'incurred_on' => '2026-10-20']);

        $actions = ActivityLog::query()->pluck('action')->all();
        foreach (['billing.invoice-drafted', 'billing.invoice-finalized', 'billing.profile-updated', 'billing.expense-added'] as $a) {
            $this->assertContains($a, $actions, $a.' missing');
        }
        $profileLog = ActivityLog::query()->where('action', 'billing.profile-updated')->firstOrFail();
        $this->assertSame(30, $profileLog->properties['changes']['terms_days']['to'] ?? null);
    }

    public function test_rates_can_be_corrected_with_a_reason_and_invoices_keep_their_amounts()
    {
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'PHP', 'tax_rate_bp' => 0]);
        $this->log(60);
        $service = app(InvoiceService::class);
        $invoice = $service->finalize($service->buildDraft($this->october, $this->finance), $this->finance);
        $card = RateCard::query()->firstOrFail();

        $this->actingAs($this->finance)->put(route('billing.rates.update', $card), ['rate' => '1600', 'effective_from' => '2026-01-01'])
            ->assertSessionHasErrors('reason');
        $this->actingAs($this->finance)->put(route('billing.rates.update', $card), ['rate' => '1600', 'effective_from' => '2026-01-01', 'reason' => 'Typo'])
            ->assertSessionHasNoErrors();

        $this->assertSame(160000, $card->fresh()?->rate_minor);
        $this->assertSame(150000, $invoice->fresh()?->subtotal_minor);
        $log = ActivityLog::query()->where('action', 'rate.set')->latest('id')->firstOrFail();
        $this->assertSame('Typo', $log->properties['reason'] ?? null);
        $this->assertSame(150000, $log->properties['from_minor'] ?? null);

        $member = User::factory()->withRole('member')->create();
        $this->actingAs($member)->put(route('billing.rates.update', $card), ['rate' => '1', 'effective_from' => '2026-01-01', 'reason' => 'x'])->assertForbidden();
    }

    public function test_currency_changes_wait_for_drafts_and_keep_history()
    {
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'USD', 'tax_rate_bp' => 0]);
        RateCard::create(['workspace_id' => $this->ws->id, 'rate_minor' => 5000, 'effective_from' => '2026-01-01', 'currency' => 'USD']);
        $this->log(60);
        $service = app(InvoiceService::class);
        $draft = $service->buildDraft($this->october, $this->finance);

        $profile = ['currency' => 'EUR', 'cycle' => 'monthly', 'retainer' => '0', 'included_hours' => '0', 'overage_rule' => 'bill',
            'terms_days' => 15, 'tax_rate' => '0', 'invoice_series' => 'INV', 'bill_to' => ['name' => 'Berlin Bakery']];
        $this->actingAs($this->finance)->put(route('billing.clients.profile', $this->ws), $profile)->assertSessionHasErrors('billing');

        $finalized = $service->finalize($draft, $this->finance);
        $this->actingAs($this->finance)->put(route('billing.clients.profile', $this->ws), $profile)
            ->assertSessionHasNoErrors()
            ->assertSessionHas('inertia.flash_data.toast', fn ($t) => str_contains($t['message'], 'no longer apply'));
        $this->assertSame('USD', $finalized->fresh()?->currency);
        $this->assertSame('EUR', BillingProfile::query()->where('workspace_id', $this->ws->id)->value('currency'));
    }
}
