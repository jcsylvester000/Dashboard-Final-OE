<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\BillingReports;
use App\Domain\Billing\FxRates;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\PaymentAccounts;
use App\Models\ActivityLog;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\ExchangeRate;
use App\Models\Invoice;
use App\Models\Payment;
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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Enhancement batch E9a: exchange rates entered by Finance (E-103), rates locked on invoices
 * and payments with realised gain/loss (E-104), payment details per currency (E-106),
 * PHP totals and unrealised FX in reports (E-107), margins in PHP (E-108).
 */
class EnhancementBatchE9aTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private Workspace $ws;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00'));
        $this->finance = User::factory()->withRole('finance')->create();
        $this->dev = User::factory()->withRole('member')->create();
        $this->ws = Workspace::factory()->create(['name' => 'Berlin Bakery']);
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'USD', 'tax_rate_bp' => 0]);
        RateCard::create(['rate_minor' => 5000, 'cost_minor' => 2000, 'effective_from' => '2026-01-01', 'currency' => 'USD']); // $50/h, costs $20/h
    }

    private function usdInvoice(): Invoice
    {
        $task = Task::factory()->create(['workspace_id' => $this->ws->id]);
        TimeEntry::create(['user_id' => $this->dev->id, 'workspace_id' => $this->ws->id, 'task_id' => $task->id, 'entry_date' => '2026-10-10', 'minutes' => 120, 'is_billable' => true])
            ->forceFill(['approved_at' => now(), 'approved_by' => $this->finance->id])->save();
        $period = BillingPeriod::create(['workspace_id' => $this->ws->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);

        return app(InvoiceService::class)->finalize(app(InvoiceService::class)->buildDraft($period, $this->finance), $this->finance); // $100.00
    }

    public function test_finance_keeps_rates_and_changes_need_a_reason()
    {
        $this->actingAs($this->finance)->post(route('billing.fx.store'), ['currency' => 'USD', 'rate_on' => '2026-11-01', 'rate' => '56', 'note' => 'BSP'])->assertSessionHasNoErrors();
        $this->actingAs($this->finance)->post(route('billing.fx.store'), ['currency' => 'USD', 'rate_on' => '2026-11-01', 'rate' => '57'])->assertSessionHasErrors('billing');
        $this->actingAs($this->finance)->post(route('billing.fx.store'), ['currency' => 'USD', 'rate_on' => '2026-11-01', 'rate' => '56.5', 'note' => 'Typo fixed'])->assertSessionHasNoErrors();
        $this->actingAs($this->finance)->post(route('billing.fx.store'), ['currency' => 'PHP', 'rate_on' => '2026-11-01', 'rate' => '1'])->assertSessionHasErrors('currency');

        $this->assertSame('56.500000', (string) ExchangeRate::query()->firstOrFail()->rate);
        $log = ActivityLog::query()->where('action', 'billing.fx-rate-changed')->firstOrFail();
        $this->assertSame('56.000000', $log->properties['before']['rate'] ?? null);

        // A day without its own rate uses the last earlier one, flagged.
        $this->assertSame(['rate' => '56.500000', 'on' => '2026-11-01', 'stale' => true], app(FxRates::class)->rate('USD', '2026-11-03'));
        $this->assertNull(app(FxRates::class)->rate('EUR', '2026-11-03'));

        $bookkeeper = User::factory()->withRole('bookkeeper')->create();
        $this->actingAs($bookkeeper)->get(route('billing.fx.index'))->assertOk();
        $this->actingAs($bookkeeper)->post(route('billing.fx.store'), ['currency' => 'USD', 'rate_on' => '2026-11-02', 'rate' => '57'])->assertForbidden();
    }

    public function test_rates_lock_on_invoice_and_payment_and_report_in_php()
    {
        app(FxRates::class)->set('USD', '2026-11-01', '56', 'BSP', $this->finance);
        $invoice = $this->usdInvoice();
        $this->assertSame('56.000000', (string) $invoice->php_rate);
        $this->assertSame('2026-11-01', $invoice->php_rate_on?->toDateString());

        // A later rate never changes the invoice; the payment takes the payment-day rate.
        app(FxRates::class)->set('USD', '2026-11-03', '58', 'BSP', $this->finance);
        app(InvoiceService::class)->recordPayment($invoice, ['amount_minor' => 4000, 'method' => 'wise', 'received_on' => '2026-11-03'], $this->finance);
        $this->assertSame('56.000000', (string) $invoice->fresh()?->php_rate);
        $this->assertSame('58.000000', (string) Payment::query()->firstOrFail()->php_rate);

        $this->actingAs($this->finance)->get(route('billing.invoices.show', $invoice))
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoice.fx.total_php_minor', 560000)
                ->where('invoice.fx.realised_minor', 8000)             // $40 x (58 - 56)
                ->where('invoice.payments.0.fx_gain_php_minor', 8000));

        $reports = app(BillingReports::class);
        $fx = $reports->foreignExchange(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
        $this->assertSame(8000, $fx['realised_php_minor']);
        $this->assertSame(12000, $fx['unrealised_php_minor']);         // $60 open x (58 - 56)
        $this->assertSame(336000, $reports->agingInPhp()['total']);   // $60 x 56
        $revenue = $reports->revenue(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
        $this->assertSame(560000, $revenue[0]['net_php_minor']);

        // E-108: margin in PHP: revenue $100 and cost $40 at the invoice's 56.
        $profit = $reports->profitability(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
        $this->assertSame(560000, $profit[0]['revenue_php_minor']);
        $this->assertSame(224000, $profit[0]['cost_php_minor']);
        $this->assertSame(336000, $profit[0]['margin_php_minor']);
    }

    public function test_an_invoice_finalized_without_a_rate_can_lock_it_once()
    {
        $invoice = $this->usdInvoice();
        $this->assertNull($invoice->php_rate);

        $this->actingAs($this->finance)->post(route('billing.invoices.lock-rate', $invoice))->assertSessionHasErrors('billing');
        app(FxRates::class)->set('USD', '2026-11-02', '57.25', 'BSP', $this->finance);
        $this->actingAs($this->finance)->post(route('billing.invoices.lock-rate', $invoice))->assertSessionHasNoErrors();
        $this->assertSame('57.250000', (string) $invoice->fresh()?->php_rate);
        $this->actingAs($this->finance)->post(route('billing.invoices.lock-rate', $invoice))->assertSessionHasErrors('billing');
    }

    public function test_documents_show_the_payment_details_for_their_currency()
    {
        $this->actingAs($this->finance)->put(route('admin.company.payment-accounts'), ['accounts' => [
            'PHP' => 'BPI 1234-5678 (PHP)', 'EUR' => 'IBAN DE89 3704 0044 0532 0130 00', 'USD' => '',
        ]])->assertSessionHasNoErrors();
        $accounts = app(PaymentAccounts::class);
        $profile = BillingProfile::query()->where('workspace_id', $this->ws->id)->firstOrFail();

        $this->assertSame('IBAN DE89 3704 0044 0532 0130 00', $accounts->for($profile, 'EUR'));
        $this->assertNull($accounts->for($profile, 'USD'));
        $profile->forceFill(['payment_instructions' => 'Pay our Wise link'])->save();
        $this->assertSame('Pay our Wise link', $accounts->for($profile, 'EUR'), "the client's own instructions win");
        $profile->forceFill(['payment_instructions' => null, 'currency' => 'EUR'])->save();

        $invoice = Invoice::create(['workspace_id' => $this->ws->id, 'series' => 'INV', 'currency' => 'EUR', 'tax_rate_bp' => 0]);
        $invoice->items()->create(['type' => 'adjustment', 'description' => 'Work', 'quantity' => '1.00', 'unit_minor' => 10000, 'amount_minor' => 10000, 'position' => 0]);
        $invoice->forceFill(['number' => 9, 'status' => Invoice::FINALIZED, 'issue_date' => '2026-11-03', 'due_date' => '2026-11-18',
            'subtotal_minor' => 10000, 'total_minor' => 10000, 'share_token' => str_repeat('e', 40)])->save();
        auth()->logout();
        $this->get(route('invoice-link.show', str_repeat('e', 40)))->assertOk()
            ->assertSee('IBAN DE89 3704 0044 0532 0130 00')->assertDontSee('BPI 1234-5678');
        $this->assertTrue(ActivityLog::query()->where('action', 'settings.payment-accounts-updated')->exists());
    }
}
