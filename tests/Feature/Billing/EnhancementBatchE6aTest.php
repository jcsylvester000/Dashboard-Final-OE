<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\InvoiceService;
use App\Models\ActivityLog;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\ClientCredit;
use App\Models\Invoice;
use App\Models\InvoiceItem;
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
use Tests\TestCase;

/**
 * Enhancement batch E6a: manual lines and discounts on drafts (E-63), second-person check
 * before finalize (E-66), issue date window (E-67), payment reversal, client credit, fees,
 * withholding and received amount (E-68 / E-105).
 */
class EnhancementBatchE6aTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $maker;

    private User $checker;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00'));

        $this->ws = Workspace::factory()->create(['name' => 'Berlin Bakery']);
        $this->maker = User::factory()->withRole('finance')->create(['name' => 'Mara Finance']);
        $this->checker = User::factory()->withRole('finance')->create(['name' => 'Carlo Finance']);
        $this->dev = User::factory()->withRole('member')->create();
        RateCard::create(['rate_minor' => 150000, 'effective_from' => '2026-01-01']); // PHP 1,500/h
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'PHP', 'tax_rate_bp' => 1200]);
    }

    private function log(Workspace $ws, int $minutes, string $date = '2026-10-10'): void
    {
        $task = Task::factory()->create(['workspace_id' => $ws->id]);
        TimeEntry::create(['user_id' => $this->dev->id, 'workspace_id' => $ws->id, 'task_id' => $task->id, 'entry_date' => $date, 'minutes' => $minutes, 'is_billable' => true])
            ->forceFill(['approved_at' => now(), 'approved_by' => $this->maker->id])->save();
    }

    private function draft(Workspace $ws, string $from = '2026-10-01', string $to = '2026-10-31'): Invoice
    {
        $period = BillingPeriod::create(['workspace_id' => $ws->id, 'starts_on' => $from, 'ends_on' => $to]);

        return app(InvoiceService::class)->buildDraft($period, $this->maker);
    }

    public function test_manual_lines_and_discounts_survive_a_rebuild_and_tax_only_taxable_lines()
    {
        $this->log($this->ws, 120); // 2h x 1,500 = 3,000.00
        $draft = $this->draft($this->ws);
        $hours = $draft->items()->firstOrFail();

        $this->actingAs($this->maker)->post(route('billing.invoices.lines.store', $draft), [
            'description' => 'Rush fee', 'quantity' => '1', 'unit_price' => '500', 'taxable' => false, 'reason' => 'Weekend delivery',
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->maker)->post(route('billing.invoices.discounts.store', $draft), ['kind' => 'percent', 'value' => '10', 'reason' => 'Loyalty'])
            ->assertSessionHasNoErrors();

        // 3,500 gross, 10% off = 3,150; the discount reduces the taxable 3,000 in proportion (2,700); tax 324.
        $draft->refresh();
        $this->assertSame(315000, $draft->subtotal_minor);
        $this->assertSame(32400, $draft->tax_minor);
        $this->assertSame(347400, $draft->total_minor);

        // A line discount on the generated hours line.
        $this->actingAs($this->maker)->post(route('billing.invoices.discounts.store', $draft), ['kind' => 'amount', 'value' => '150', 'applies_to_item_id' => $hours->id, 'reason' => 'Goodwill'])
            ->assertSessionHasNoErrors();
        $this->assertSame(330600, $draft->fresh()?->total_minor); // 3,000 - 350 - 150 = 3,000 subtotal; taxable 2,550; tax 306

        // Generated lines can't be removed by hand.
        $this->actingAs($this->maker)->delete(route('billing.invoice-lines.destroy', $hours))->assertSessionHasErrors('billing');

        // Rebuild: the manual line and the whole-invoice discount stay; the line discount goes with its line.
        $this->actingAs($this->maker)->post(route('billing.invoices.recalculate', $draft))->assertSessionHasNoErrors();
        $draft->refresh();
        $this->assertSame(['hours', 'adjustment', 'discount'], $draft->items()->pluck('type')->all());
        $this->assertSame(347400, $draft->total_minor);
        $this->assertStringContainsString('line discount(s) removed', (string) $draft->history()->reorder()->latest('id')->value('note'));

        $draft->load('items', 'workspace');
        $html = view('pdf.invoice', ['invoice' => $draft, 'company' => ['name' => 'OverEasy', 'address' => null, 'tax_id' => null, 'contact' => null],
            'logo' => null, 'paymentInstructions' => null, 'draft' => true])->render();
        $this->assertStringContainsString('Rush fee *', $html);
        $this->assertStringContainsString('Not subject to', $html);
        $this->assertStringContainsString('Discount 10% – Loyalty', $html);

        $rush = InvoiceItem::query()->where('description', 'Rush fee')->firstOrFail();
        $this->actingAs($this->maker)->delete(route('billing.invoice-lines.destroy', $rush))->assertSessionHasNoErrors();
        $this->assertSame(302400, $draft->fresh()?->total_minor); // 3,000 - 300 = 2,700 + 324
        $this->assertTrue(ActivityLog::query()->where('action', 'billing.invoice-adjusted')->exists());
    }

    public function test_a_second_person_checks_invoices_above_the_threshold()
    {
        $this->actingAs($this->maker)->put(route('admin.company.finance'), ['review' => 'above', 'thresholds' => ['PHP' => '1000'], 'backdate_days' => 31])
            ->assertSessionHasNoErrors();
        $this->log($this->ws, 120);
        $draft = $this->draft($this->ws);

        $this->actingAs($this->maker)->post(route('billing.invoices.finalize', $draft))->assertSessionHasErrors('billing');
        $this->actingAs($this->maker)->post(route('billing.invoices.review', $draft))->assertSessionHasNoErrors();
        $this->actingAs($this->maker)->post(route('billing.invoices.finalize', $draft))->assertSessionHasErrors('billing');

        // The checker sends it back; the maker fixes and resends; any change cancels the request.
        $this->actingAs($this->checker)->post(route('billing.invoices.send-back', $draft), ['note' => 'Add the rush fee'])->assertSessionHasNoErrors();
        $this->assertNull($draft->fresh()?->review_requested_at);
        $this->assertSame('Add the rush fee', $draft->fresh()?->review_note);
        $this->actingAs($this->maker)->post(route('billing.invoices.review', $draft));
        $this->actingAs($this->maker)->post(route('billing.invoices.lines.store', $draft), ['description' => 'Rush fee', 'quantity' => '1', 'unit_price' => '500', 'taxable' => true]);
        $this->assertNull($draft->fresh()?->review_requested_at, 'a change after the request reopens the review');

        $this->actingAs($this->maker)->post(route('billing.invoices.review', $draft));
        $this->actingAs($this->checker)->get(route('billing.invoices.show', $draft))
            ->assertInertia(fn ($page) => $page->where('invoice.review.can_finalize', true)->where('invoice.review.requested_by', 'Mara Finance'));
        $this->actingAs($this->checker)->post(route('billing.invoices.finalize', $draft))->assertSessionHasNoErrors();
        $this->assertSame(Invoice::FINALIZED, $draft->fresh()?->status);
        $this->assertSame('Checked and finalized', $draft->history()->where('to_status', Invoice::FINALIZED)->value('note'));

        // Below the threshold: one person is enough.
        $small = Workspace::factory()->create();
        BillingProfile::create(['workspace_id' => $small->id, 'currency' => 'PHP', 'tax_rate_bp' => 0]);
        $this->log($small, 30);
        $this->actingAs($this->maker)->post(route('billing.invoices.finalize', $this->draft($small)))->assertSessionHasNoErrors();
    }

    public function test_issue_date_stays_inside_the_window_and_in_series_order()
    {
        $this->log($this->ws, 60);
        $first = $this->draft($this->ws);
        $this->actingAs($this->maker)->post(route('billing.invoices.finalize', $first), ['issue_date' => '2026-11-04'])->assertSessionHasErrors('billing');
        $this->actingAs($this->maker)->post(route('billing.invoices.finalize', $first), ['issue_date' => '2026-09-01'])->assertSessionHasErrors('billing');
        $this->actingAs($this->maker)->post(route('billing.invoices.finalize', $first), ['issue_date' => '2026-10-31'])->assertSessionHasNoErrors();

        $other = Workspace::factory()->create();
        BillingProfile::create(['workspace_id' => $other->id, 'currency' => 'PHP', 'tax_rate_bp' => 0]);
        $this->log($other, 60);
        $second = $this->draft($other);
        $this->actingAs($this->maker)->post(route('billing.invoices.finalize', $second), ['issue_date' => '2026-10-30'])->assertSessionHasErrors('billing');
        $this->actingAs($this->maker)->post(route('billing.invoices.finalize', $second), ['issue_date' => '2026-11-01'])->assertSessionHasNoErrors();
        $this->assertSame('2026-11-16', $second->fresh()?->due_date?->toDateString()); // 15-day terms from the issue date
    }

    public function test_overpayment_becomes_credit_that_pays_the_next_invoice_and_reversals_unwind_it()
    {
        $this->log($this->ws, 120);
        $october = app(InvoiceService::class)->finalize($this->draft($this->ws), $this->maker); // 3,360.00
        $this->actingAs($this->maker)->post(route('billing.invoices.payments.store', $october), ['amount' => '3400', 'method' => 'bank', 'received_on' => '2026-11-03'])
            ->assertSessionHasNoErrors();
        $this->assertSame(Invoice::PAID, $october->fresh()?->status);
        $this->assertSame(4000, ClientCredit::balance($this->ws->id, 'PHP'));

        $this->log($this->ws, 60, '2026-11-02');
        $november = app(InvoiceService::class)->finalize($this->draft($this->ws, '2026-11-01', '2026-11-30'), $this->maker); // 1,680.00
        $november->refresh();
        $this->assertSame(4000, $november->paid_minor, 'credit applied at finalize');
        $this->assertSame(Invoice::PARTIALLY_PAID, $november->status);
        $this->assertSame(0, ClientCredit::balance($this->ws->id, 'PHP'));

        // The October payment made credit that is already used: reverse the credit use first.
        $octPayment = Payment::query()->where('invoice_id', $october->id)->firstOrFail();
        $this->actingAs($this->maker)->post(route('billing.payments.reverse', $octPayment), ['reason' => 'Wrong client'])->assertSessionHasErrors('billing');
        $creditUse = Payment::query()->where('invoice_id', $november->id)->where('method', 'credit')->firstOrFail();
        $this->actingAs($this->maker)->post(route('billing.payments.reverse', $creditUse), ['reason' => 'Undo'])->assertSessionHasNoErrors();
        $this->assertSame(Invoice::FINALIZED, $november->fresh()?->status);
        $this->assertSame(4000, ClientCredit::balance($this->ws->id, 'PHP'));

        $this->actingAs($this->maker)->post(route('billing.payments.reverse', $octPayment), ['reason' => 'Wrong client'])->assertSessionHasNoErrors();
        $this->assertSame(Invoice::FINALIZED, $october->fresh()?->status);
        $this->assertSame(0, $october->fresh()?->paid_minor);
        $this->assertSame(0, ClientCredit::balance($this->ws->id, 'PHP'));
        $this->assertNotNull($octPayment->fresh()?->reversed_at);
        $this->actingAs($this->maker)->post(route('billing.payments.reverse', $octPayment), ['reason' => 'Again'])->assertSessionHasErrors('billing');
        $this->assertTrue(ActivityLog::query()->where('action', 'billing.invoice-payment-reversed')->exists());
    }

    public function test_a_foreign_payment_records_fees_withholding_and_the_amount_received()
    {
        $usd = Workspace::factory()->create();
        BillingProfile::create(['workspace_id' => $usd->id, 'currency' => 'USD', 'tax_rate_bp' => 0]);
        RateCard::create(['rate_minor' => 5000, 'effective_from' => '2026-01-01', 'currency' => 'USD']);
        $this->log($usd, 120);
        $invoice = app(InvoiceService::class)->finalize($this->draft($usd), $this->maker); // $100.00

        $this->actingAs($this->maker)->post(route('billing.invoices.payments.store', $invoice), [
            'amount' => '100', 'fee' => '2.50', 'method' => 'wise', 'received_on' => '2026-11-03',
            'received_amount' => '5600', 'received_currency' => 'PHP',
        ])->assertSessionHasNoErrors();

        $payment = Payment::query()->where('invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(Invoice::PAID, $invoice->fresh()?->status, 'the fee does not leave a balance');
        $this->assertSame(250, $payment->fee_minor);
        $this->assertSame(560000, $payment->received_minor);
        $this->assertSame('PHP', $payment->received_currency);
        $this->assertSame('57.43589744', (string) $payment->fx_rate); // 5,600 PHP / 97.50 USD net
        $this->actingAs($this->maker)->get(route('billing.invoices.show', $invoice))
            ->assertInertia(fn ($page) => $page->where('invoice.payments.0.fee_minor', 250)->where('invoice.payments.0.received_currency', 'PHP'));
    }
}
