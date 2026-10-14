<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\StatementService;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\Expense;
use App\Models\InboxNotification;
use App\Models\Invoice;
use App\Models\RateCard;
use App\Models\Task;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Enhancement batch E6b: statement of account (E-69), client invoice link (E-70), overdue
 * follow-up stages and promise-to-pay (E-72), periods and retainer drafts by schedule (E-73),
 * expense markup, receipts and approval (E-75).
 */
class EnhancementBatchE6bTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $finance;

    private User $checker;

    private User $dev;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00'));

        $this->ws = Workspace::factory()->create(['name' => 'Berlin Bakery']);
        $this->finance = User::factory()->withRole('finance')->create();
        $this->checker = User::factory()->withRole('finance')->create();
        $this->dev = User::factory()->withRole('member')->create();
        RateCard::create(['rate_minor' => 150000, 'effective_from' => '2026-01-01']);
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'PHP', 'tax_rate_bp' => 1200, 'payment_instructions' => 'BPI 1234-5678']);
    }

    private function log(int $minutes, string $date = '2026-10-10'): void
    {
        $task = Task::factory()->create(['workspace_id' => $this->ws->id]);
        TimeEntry::create(['user_id' => $this->dev->id, 'workspace_id' => $this->ws->id, 'task_id' => $task->id, 'entry_date' => $date, 'minutes' => $minutes, 'is_billable' => true])
            ->forceFill(['approved_at' => now(), 'approved_by' => $this->finance->id])->save();
    }

    private function invoice(string $from = '2026-10-01', string $to = '2026-10-31'): Invoice
    {
        $period = BillingPeriod::create(['workspace_id' => $this->ws->id, 'starts_on' => $from, 'ends_on' => $to]);

        return app(InvoiceService::class)->finalize(app(InvoiceService::class)->buildDraft($period, $this->finance), $this->finance);
    }

    public function test_expenses_carry_markup_receipts_and_wait_for_approval_when_required()
    {
        $this->actingAs($this->finance)->put(route('admin.company.finance'), [
            'review' => 'off', 'backdate_days' => 31, 'expense_approval' => true, 'receipt_thresholds' => ['PHP' => '1000'],
        ])->assertSessionHasNoErrors();

        $form = ['description' => 'Facebook ad spend', 'amount' => '5000', 'incurred_on' => '2026-10-20', 'category' => 'ad_spend', 'markup' => '10', 'taxable' => false];
        $this->actingAs($this->finance)->post(route('billing.clients.expenses.store', $this->ws), $form)->assertSessionHasErrors('billing'); // receipt required
        $this->actingAs($this->finance)->post(route('billing.clients.expenses.store', $this->ws), [...$form, 'receipt' => UploadedFile::fake()->create('meta-receipt.pdf', 40, 'application/pdf')])
            ->assertSessionHasNoErrors();
        $expense = Expense::query()->firstOrFail();
        $this->assertFalse($expense->isApproved());
        $this->assertSame(550000, $expense->billedMinor());
        Storage::disk('local')->assertExists((string) $expense->receipt_path);
        $this->actingAs($this->finance)->get(route('billing.expenses.receipt', $expense))->assertOk();

        // Not on the draft until someone else approves it.
        $this->log(60);
        $period = BillingPeriod::create(['workspace_id' => $this->ws->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $draft = app(InvoiceService::class)->buildDraft($period, $this->finance);
        $this->assertFalse($draft->items()->where('type', 'expense')->exists());

        $this->actingAs($this->finance)->post(route('billing.expenses.approve', $expense))->assertSessionHasErrors('billing');
        $this->actingAs($this->checker)->post(route('billing.expenses.approve', $expense))->assertSessionHasNoErrors();

        $draft = app(InvoiceService::class)->buildDraft($period->fresh() ?? $period, $this->finance);
        $line = $draft->items()->where('type', 'expense')->firstOrFail();
        $this->assertSame(550000, $line->amount_minor);
        $this->assertFalse($line->taxable);
        $this->assertStringContainsString('incl. 10% handling', $line->description);
        $this->assertSame(150000 + 550000 + 18000, $draft->total_minor, 'tax only on the hours');

        // On a draft it can't be edited; delete the draft first.
        $this->actingAs($this->finance)->post(route('billing.expenses.update', $expense), $form)->assertSessionHasErrors('billing');
    }

    public function test_editing_an_approved_expense_amount_needs_a_fresh_approval()
    {
        $this->actingAs($this->finance)->put(route('admin.company.finance'), ['review' => 'off', 'backdate_days' => 31, 'expense_approval' => true]);
        $expense = Expense::create(['workspace_id' => $this->ws->id, 'description' => 'Stock photos', 'amount_minor' => 50000, 'incurred_on' => '2026-10-20', 'created_by' => $this->finance->id]);
        $this->actingAs($this->checker)->post(route('billing.expenses.approve', $expense));
        $this->assertTrue($expense->fresh()?->isApproved());

        $this->actingAs($this->finance)->post(route('billing.expenses.update', $expense), ['description' => 'Stock photos (3)', 'amount' => '750', 'incurred_on' => '2026-10-20'])
            ->assertSessionHasNoErrors();
        $this->assertFalse($expense->fresh()?->isApproved());
    }

    public function test_client_invoice_link_records_views_and_dies_when_revoked()
    {
        $this->log(120);
        $invoice = $this->invoice();
        $this->actingAs($this->finance)->post(route('billing.invoices.link', $invoice), ['expires_days' => 30])->assertSessionHasNoErrors();
        $token = (string) $invoice->fresh()?->share_token;
        auth()->logout();

        $this->get(route('invoice-link.show', $token))->assertOk()
            ->assertSee($invoice->displayNumber())->assertSee('BPI 1234-5678')->assertSee('noindex', false)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(route('invoice-link.show', $token))->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $this->get(route('invoice-link.pdf', $token))->assertOk()->getContent());

        $invoice->refresh();
        $this->assertSame(2, $invoice->share_views);
        $this->assertNotNull($invoice->share_first_viewed_at);
        $this->assertSame(1, $invoice->history()->where('note', 'Opened by the client (link)')->count());

        $this->travelTo(CarbonImmutable::parse('2026-12-04 09:00:00')); // past the 30 days
        $this->get(route('invoice-link.show', $token))->assertNotFound();

        $this->actingAs($this->finance)->post(route('billing.invoices.link', $invoice));
        $fresh = (string) $invoice->fresh()?->share_token;
        $this->actingAs($this->finance)->delete(route('billing.invoices.unlink', $invoice));
        auth()->logout();
        $this->get(route('invoice-link.show', $fresh))->assertNotFound();
        $this->get(route('invoice-link.show', str_repeat('a', 40)))->assertNotFound();
    }

    public function test_statement_balances_match_open_invoices_less_unused_credit()
    {
        $this->log(120);
        $october = $this->invoice(); // 3,360.00
        $this->log(60, '2026-11-02');
        $this->travelTo(CarbonImmutable::parse('2026-12-02 09:00:00'));
        $november = $this->invoice('2026-11-01', '2026-11-30'); // 1,680.00
        app(InvoiceService::class)->recordPayment($october, ['amount_minor' => 340000, 'method' => 'bank', 'received_on' => '2026-12-02'], $this->finance); // 40.00 extra -> credit
        app(InvoiceService::class)->creditNote($november->fresh() ?? $november, 50000, 'Goodwill', $this->finance); // 500 + 60 tax

        $s = app(StatementService::class)->build($this->ws, 'PHP', CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-12-02'));
        $this->assertSame(336000 + 168000, $s['invoiced_minor']);
        $this->assertSame(340000 + 56000, $s['received_minor']);
        $open = $s['aging']['total'];
        $this->assertSame(168000 - 56000, $open);
        $this->assertSame(4000, $s['unused_credit_minor']);
        $this->assertSame($open - $s['unused_credit_minor'], $s['closing_minor']);
        $this->assertSame(0, $s['opening_minor']);

        $pdf = $this->actingAs($this->finance)->get(route('billing.clients.statement', ['workspace' => $this->ws, 'currency' => 'PHP', 'from' => '2026-10-01']))->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        $this->actingAs($this->finance)->post(route('billing.clients.statement-link', $this->ws), ['expires_days' => 30])->assertSessionHasNoErrors();
        $token = (string) BillingProfile::query()->where('workspace_id', $this->ws->id)->value('statement_token');
        auth()->logout();
        $this->get(route('statement-link.show', $token))->assertOk()->assertSee('Statement of account')->assertSee($november->displayNumber());
        $this->assertStringStartsWith('%PDF', (string) $this->get(route('statement-link.pdf', $token))->getContent());
        $this->actingAs($this->finance)->delete(route('billing.clients.statement-unlink', $this->ws));
        auth()->logout();
        $this->get(route('statement-link.show', $token))->assertNotFound();
    }

    public function test_overdue_follow_ups_run_in_stages_pause_for_a_promise_and_reach_the_lead_at_30_days()
    {
        $lead = User::factory()->withRole('member')->create();
        $this->ws->members()->attach($lead->id, ['role' => Workspace::ROLE_LEAD]);
        $this->log(120);
        $invoice = $this->invoice(); // due 2026-11-18
        $count = fn (User $u) => InboxNotification::where('notifiable_id', $u->id)->where('kind', 'invoice_overdue')->count();

        $this->travelTo(CarbonImmutable::parse('2026-11-25 08:00:00')); // 7 days
        $this->artisan('billing:alerts');
        $this->artisan('billing:alerts');
        $this->assertSame(2, $count($this->finance), 'first overdue alert + stage 1, each once');
        $this->assertSame(1, $invoice->fresh()?->collection_stage);

        // A promise pauses the stages.
        $this->actingAs($this->finance)->post(route('billing.invoices.follow-ups.store', $invoice), ['note' => 'Called Carla', 'promise_to_pay_on' => '2026-12-05'])
            ->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2026-12-02 08:00:00')); // 14 days, promise still ahead
        $this->artisan('billing:alerts');
        $this->assertSame(2, $count($this->finance));

        $this->travelTo(CarbonImmutable::parse('2026-12-06 08:00:00')); // promise broken
        $this->artisan('billing:alerts');
        $this->assertSame(3, $count($this->finance));
        $this->assertNull($invoice->fresh()?->promise_to_pay_on);
        $this->artisan('billing:alerts'); // stage 2 (18 days)
        $this->assertSame(4, $count($this->finance));
        $this->assertSame(0, $count($lead));

        $this->travelTo(CarbonImmutable::parse('2026-12-18 08:00:00')); // 30 days
        $this->artisan('billing:alerts');
        $this->assertSame(1, $count($lead));
        $leadNote = InboxNotification::where('notifiable_id', $lead->id)->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('₱', json_encode($leadNote->data, JSON_UNESCAPED_UNICODE) ?: '', 'leads never see amounts');

        $this->actingAs($this->finance)->get(route('billing.invoices.index'))
            ->assertInertia(fn ($page) => $page->where('invoices.data.0.days_overdue', 30));
    }

    public function test_the_schedule_opens_periods_and_builds_each_retainer_draft_once()
    {
        BillingProfile::query()->where('workspace_id', $this->ws->id)->update(['retainer_minor' => 5000000, 'included_minutes' => 600]);

        $this->artisan('billing:periods')->assertSuccessful();
        $this->artisan('billing:periods')->assertSuccessful();
        $this->assertSame([['2026-11-01', '2026-11-30']], BillingPeriod::query()->get()->map(fn ($p) => [$p->starts_on->toDateString(), $p->ends_on->toDateString()])->all());

        $this->travelTo(CarbonImmutable::parse('2026-12-01 07:00:00'));
        $this->artisan('billing:periods')->assertSuccessful();
        $this->artisan('billing:periods')->assertSuccessful();

        $this->assertSame(2, BillingPeriod::query()->count());
        $november = BillingPeriod::query()->whereDate('starts_on', '2026-11-01')->firstOrFail();
        $this->assertNotNull($november->auto_drafted_at);
        $this->assertSame(1, Invoice::query()->where('billing_period_id', $november->id)->count());
        $this->assertSame(Invoice::DRAFT, Invoice::query()->where('billing_period_id', $november->id)->value('status'));
        $this->assertSame(1, InboxNotification::where('notifiable_id', $this->finance->id)->where('kind', 'billing_draft')->count());
    }
}
