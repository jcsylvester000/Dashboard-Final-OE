<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\BillingException;
use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\Money;
use App\Domain\Billing\PeriodService;
use App\Domain\Billing\RateResolver;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\Department;
use App\Models\Expense;
use App\Models\InboxNotification;
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
 * Billing fixture ("now" = 2026-11-03), October 2026 period, 12% VAT:
 *   rates: agency default 1,000/h; agency SEO 1,500/h; client Development 1,200/h;
 *          client + Max from 2026-10-15: 2,000/h
 *   e1 Max  dev 10-05  90 min -> client dev 1,200 (Max's card not effective yet)
 *   e2 Max  seo 10-20  45 min -> client+Max 2,000
 *   e3 Lena seo 10-10  20 min -> agency SEO 1,500
 *   e4 Lena dev 10-11   7 min -> client dev 1,200
 *   not billed: unapproved, non-billable, dated in November
 * Hours lines: dev 97 min x 1,200 = 1,940.00; seo 20 min x 1,500 = 500.00; seo 45 min x 2,000 = 1,500.00
 * Subtotal 3,940.00, VAT 472.80, total 4,412.80.
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $ws;

    private User $finance;

    private User $max;

    private User $lena;

    private Task $devTask;

    private Task $seoTask;

    private BillingPeriod $october;

    /** @var array<string, TimeEntry> */
    private array $e = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local'); // finalize stores the invoice PDF
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00:00'));

        $this->ws = Workspace::factory()->create(['name' => 'Client Co']);
        $this->finance = User::factory()->withRole('finance')->create();
        $this->max = User::factory()->withRole('member')->create(['name' => 'Max']);
        $this->lena = User::factory()->withRole('member')->create(['name' => 'Lena']);
        $this->ws->members()->attach($this->max->id, ['role' => Workspace::ROLE_MEMBER]);
        $this->ws->members()->attach($this->lena->id, ['role' => Workspace::ROLE_LEAD]);

        $dev = $this->dept('development');
        $seo = $this->dept('seo');
        $this->devTask = Task::factory()->create(['workspace_id' => $this->ws->id, 'department_id' => $dev]);
        $this->seoTask = Task::factory()->create(['workspace_id' => $this->ws->id, 'department_id' => $seo]);

        BillingProfile::create(['workspace_id' => $this->ws->id, 'tax_rate_bp' => 1200, 'terms_days' => 15, 'bill_to' => ['name' => 'Client Co Inc.']]);

        $this->rate(null, null, null, 100000, '2026-01-01');
        $this->rate(null, $seo, null, 150000, '2026-01-01');
        $this->rate($this->ws->id, $dev, null, 120000, '2026-01-01');
        $this->rate($this->ws->id, null, $this->max->id, 200000, '2026-10-15');

        $this->e['e1'] = $this->entry($this->max, $this->devTask, '2026-10-05', 90);
        $this->e['e2'] = $this->entry($this->max, $this->seoTask, '2026-10-20', 45);
        $this->e['e3'] = $this->entry($this->lena, $this->seoTask, '2026-10-10', 20);
        $this->e['e4'] = $this->entry($this->lena, $this->devTask, '2026-10-11', 7);
        $this->entry($this->max, $this->devTask, '2026-10-12', 60, approved: false);
        $this->entry($this->max, $this->devTask, '2026-10-13', 60, billable: false);
        $this->entry($this->max, $this->devTask, '2026-11-01', 60);

        $this->october = BillingPeriod::create(['workspace_id' => $this->ws->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
    }

    private function dept(string $slug): int
    {
        return (int) Department::where('slug', $slug)->value('id');
    }

    private function rate(?int $ws, ?int $dept, ?int $user, int $minor, string $from): void
    {
        RateCard::create(['workspace_id' => $ws, 'department_id' => $dept, 'user_id' => $user, 'rate_minor' => $minor, 'effective_from' => $from]);
    }

    private function entry(User $user, Task $task, string $date, int $minutes, bool $approved = true, bool $billable = true): TimeEntry
    {
        $entry = TimeEntry::create([
            'user_id' => $user->id, 'workspace_id' => $this->ws->id, 'task_id' => $task->id,
            'department_id' => $task->department_id, 'entry_date' => $date, 'minutes' => $minutes, 'is_billable' => $billable,
        ]);
        if ($approved) {
            $entry->forceFill(['approved_at' => now(), 'approved_by' => $this->finance->id])->save();
        }

        return $entry;
    }

    private function service(): InvoiceService
    {
        return app(InvoiceService::class);
    }

    private function draft(): Invoice
    {
        return $this->service()->buildDraft($this->october, $this->finance);
    }

    public function test_money_is_integer_and_rounds_half_away_from_zero()
    {
        $this->assertSame(11667, Money::mulDiv(7, 100001, 60));
        $this->assertSame(2, Money::mulDiv(1, 150, 100));
        $this->assertSame(-2, Money::mulDiv(-1, 150, 100));
        $this->assertSame(47280, Money::tax(394000, 1200));
        $this->assertSame(250050, Money::parse('2,500.50'));
        $this->assertSame(250000, Money::parse('2500.5') - 50);
        $this->assertSame('2500.50', Money::toDecimal(250050));
        $this->assertSame("\u{20B1}4,412.80", Money::format(441280, 'PHP'));
    }

    public function test_members_cannot_see_billing()
    {
        $this->actingAs($this->max)->get(route('billing.invoices.index'))->assertForbidden();
        $this->actingAs($this->lena)->get(route('billing.clients.show', $this->ws))->assertForbidden();
        $this->actingAs($this->max)->get(route('billing.rates.index'))->assertForbidden();

        $this->actingAs($this->finance)->get(route('billing.invoices.index'))->assertOk();
        $this->actingAs($this->finance)->get(route('billing.clients.show', $this->ws))->assertOk();
    }

    public function test_rate_precedence_and_effective_dates()
    {
        $r = new RateResolver;
        $dev = $this->dept('development');
        $seo = $this->dept('seo');
        $mkt = $this->dept('marketing');

        $this->assertSame(120000, $r->resolve($this->ws->id, $dev, $this->max->id, '2026-10-05')?->rate_minor);
        $this->assertSame(200000, $r->resolve($this->ws->id, $dev, $this->max->id, '2026-10-15')?->rate_minor);
        $this->assertSame(150000, $r->resolve($this->ws->id, $seo, $this->lena->id, '2026-10-10')?->rate_minor);
        $this->assertSame(100000, $r->resolve($this->ws->id, $mkt, $this->lena->id, '2026-10-10')?->rate_minor);
        $this->assertNull($r->resolve($this->ws->id, $dev, null, '2025-12-31'));
    }

    public function test_draft_invoice_lines_trace_back_to_time_entries()
    {
        $invoice = $this->draft()->fresh(['items.sources']);

        $this->assertSame(Invoice::DRAFT, $invoice->status);
        $this->assertSame(394000, $invoice->subtotal_minor);
        $this->assertSame(47280, $invoice->tax_minor);
        $this->assertSame(441280, $invoice->total_minor);

        $lines = $invoice->items->map(fn ($i) => [$i->minutes, $i->unit_minor, $i->amount_minor])->sort()->values()->all();
        $this->assertSame([[20, 150000, 50000], [45, 200000, 150000], [97, 120000, 194000]], $lines);

        // Every line drills down to its entries; minutes add up.
        foreach ($invoice->items as $item) {
            $this->assertNotEmpty($item->sources);
            $this->assertSame($item->minutes, $item->sources->sum('minutes'));
        }
        $billed = $invoice->items->flatMap->sources->pluck('source_id')->sort()->values()->all();
        $this->assertSame(collect($this->e)->pluck('id')->sort()->values()->all(), $billed);

        // Building the draft locked the period.
        $this->assertSame(BillingPeriod::LOCKED, $this->october->fresh()->status);
    }

    public function test_locked_period_rejects_new_and_changed_time()
    {
        $this->draft();

        $this->actingAs($this->max)
            ->post(route('workspaces.tasks.time.store', [$this->ws, $this->devTask]), ['entry_date' => '2026-10-12', 'hours' => 1])
            ->assertSessionHasErrors('entry');

        $this->actingAs($this->max)
            ->post(route('workspaces.tasks.time.store', [$this->ws, $this->devTask]), ['entry_date' => '2026-11-02', 'hours' => 1])
            ->assertSessionHasNoErrors();

        // Unlocking removes the stale draft and allows time again.
        app(PeriodService::class)->unlock($this->october->fresh());
        $this->assertSame(0, Invoice::count());
        $this->actingAs($this->max)
            ->post(route('workspaces.tasks.time.store', [$this->ws, $this->devTask]), ['entry_date' => '2026-10-12', 'hours' => 1])
            ->assertSessionHasNoErrors();
    }

    public function test_finalize_numbers_freezes_and_refuses_edits()
    {
        $invoice = $this->draft();

        $this->actingAs($this->finance)->post(route('billing.invoices.finalize', $invoice))->assertRedirect();
        $invoice->refresh();

        $this->assertSame(Invoice::FINALIZED, $invoice->status);
        $this->assertSame('INV-0001', $invoice->displayNumber());
        $this->assertSame('2026-11-18', $invoice->due_date?->toDateString());
        $this->assertSame(['name' => 'Client Co Inc.'], $invoice->bill_to);
        $this->assertNotNull($this->e['e1']->fresh()->locked_at);
        $this->assertSame(BillingPeriod::INVOICED, $this->october->fresh()->status);

        try {
            $invoice->forceFill(['total_minor' => 1])->save();
            $this->fail('Finalized invoice was changed.');
        } catch (BillingException) {
        }
        try {
            $invoice->items()->first()?->update(['amount_minor' => 1]);
            $this->fail('Finalized line was changed.');
        } catch (BillingException) {
        }
        $this->assertSame(441280, $invoice->fresh()->total_minor);

        // The UI routes refuse too, with a message instead of an error page.
        $this->actingAs($this->finance)->put(route('billing.invoices.update', $invoice), ['notes' => 'x'])->assertSessionHasErrors('billing');
        $this->actingAs($this->finance)->post(route('billing.invoices.recalculate', $invoice))->assertSessionHasErrors('billing');
        $this->actingAs($this->finance)->delete(route('billing.invoices.destroy', $invoice))->assertSessionHasErrors('billing');

        // Next invoice in the series gets the next number.
        $november = BillingPeriod::create(['workspace_id' => $this->ws->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
        $next = $this->service()->finalize($this->service()->buildDraft($november, $this->finance), $this->finance);
        $this->assertSame(2, $next->number);
    }

    public function test_payments_balance_history_and_aging()
    {
        $invoice = $this->service()->finalize($this->draft(), $this->finance);

        $this->actingAs($this->finance)->post(route('billing.invoices.payments.store', $invoice), [
            'amount' => '1000.00', 'method' => 'gcash', 'reference' => 'GC-123', 'received_on' => '2026-11-03',
        ])->assertSessionHasNoErrors();
        $invoice->refresh();
        $this->assertSame(Invoice::PARTIALLY_PAID, $invoice->status);
        $this->assertSame(341280, $invoice->balanceMinor());

        $this->actingAs($this->finance)->post(route('billing.invoices.payments.store', $invoice), [
            'amount' => '5000.00', 'method' => 'bank', 'received_on' => '2026-11-03',
        ])->assertSessionHasErrors('billing');

        // 40 days after the due date (11-18) it sits in the 31-60 bucket.
        $this->travelTo(CarbonImmutable::parse('2026-12-28 09:00:00'));
        $aging = $this->service()->aging();
        $this->assertSame(341280, $aging['PHP']['31_60']);
        $this->assertSame(341280, $aging['PHP']['total']);

        $this->actingAs($this->finance)->post(route('billing.invoices.payments.store', $invoice), [
            'amount' => '3412.80', 'method' => 'bank', 'received_on' => '2026-12-28',
        ])->assertSessionHasNoErrors();
        $this->assertSame(Invoice::PAID, $invoice->fresh()->status);
        $this->assertSame([], $this->service()->aging());

        $this->assertSame(
            ['draft', 'finalized', 'partially_paid', 'paid'],
            $invoice->history()->pluck('to_status')->unique()->values()->all(),
        );
    }

    public function test_credit_note_reduces_the_balance_without_touching_the_invoice()
    {
        $invoice = $this->service()->finalize($this->draft(), $this->finance);

        $this->actingAs($this->finance)->post(route('billing.invoices.credit', $invoice), ['amount' => '100.00', 'reason' => 'Goodwill'])->assertRedirect();

        $credit = Invoice::where('kind', 'credit_note')->firstOrFail();
        $this->assertSame('CN-0001', $credit->displayNumber());
        $this->assertSame(-11200, $credit->total_minor);

        $invoice->refresh();
        $this->assertSame(441280, $invoice->total_minor);
        $this->assertSame(11200, $invoice->credited_minor);
        $this->assertSame(430080, $invoice->balanceMinor());
        $this->assertSame(Invoice::PARTIALLY_PAID, $invoice->status);
    }

    public function test_void_releases_time_for_rebilling()
    {
        $invoice = $this->service()->finalize($this->draft(), $this->finance);

        $this->actingAs($this->finance)->post(route('billing.invoices.cancel', $invoice), ['reason' => 'Wrong period'])->assertRedirect();

        $this->assertSame(Invoice::VOID, $invoice->fresh()->status);
        $this->assertNull($this->e['e1']->fresh()->locked_at);
        $this->assertSame(BillingPeriod::LOCKED, $this->october->fresh()->status);

        $again = $this->draft();
        $this->assertNotSame($invoice->id, $again->id);
        $this->assertSame(394000, $again->subtotal_minor);
    }

    public function test_retainer_covers_included_hours_and_bills_or_absorbs_the_rest()
    {
        BillingProfile::where('workspace_id', $this->ws->id)->update(['retainer_minor' => 5000000, 'included_minutes' => 60]);

        $invoice = $this->draft()->fresh(['items.sources']);
        $retainer = $invoice->items->firstWhere('type', 'retainer');
        $this->assertSame(5000000, $retainer->amount_minor);
        $this->assertSame(60, $retainer->sources->sum('minutes'));
        // Over: dev 30+7 min x 1,200 = 740.00; seo 20 x 1,500 = 500.00; seo 45 x 2,000 = 1,500.00
        $this->assertSame(5000000 + 74000 + 50000 + 150000, $invoice->subtotal_minor);

        BillingProfile::where('workspace_id', $this->ws->id)->update(['overage_rule' => 'absorb']);
        $absorbed = $this->draft()->fresh(['items.sources']);
        $this->assertSame(5000000, $absorbed->subtotal_minor);
        $this->assertCount(1, $absorbed->items);
        $this->assertSame(162, $absorbed->items->first()->sources->sum('minutes'));
    }

    public function test_expenses_and_fixed_fees_are_billed_once()
    {
        $project = Project::factory()->create(['workspace_id' => $this->ws->id, 'status' => 'completed']);
        $project->forceFill(['fixed_fee_minor' => 1000000])->save();
        $this->actingAs($this->finance)->post(route('billing.clients.expenses.store', $this->ws), [
            'description' => 'Stock photos', 'amount' => '2,500.50', 'incurred_on' => '2026-10-15', 'is_billable' => true,
        ])->assertSessionHasErrors('amount');
        $this->actingAs($this->finance)->post(route('billing.clients.expenses.store', $this->ws), [
            'description' => 'Stock photos', 'amount' => '2500.50', 'incurred_on' => '2026-10-15', 'is_billable' => true,
        ])->assertSessionHasNoErrors();

        $invoice = $this->service()->finalize($this->draft(), $this->finance);
        $this->assertSame(394000 + 1000000 + 250050, $invoice->subtotal_minor);
        $this->assertNotNull(Expense::firstOrFail()->invoice_item_id);
        $this->assertNotNull($project->fresh()->fixed_fee_billed_at);

        $november = BillingPeriod::create(['workspace_id' => $this->ws->id, 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
        $next = $this->service()->buildDraft($november, $this->finance)->fresh('items');
        $this->assertSame(['hours'], $next->items->pluck('type')->unique()->values()->all());
    }

    public function test_missing_rate_is_reported_not_guessed()
    {
        RateCard::query()->delete();

        $this->actingAs($this->finance)->post(route('billing.periods.invoice', $this->october))->assertSessionHasErrors('billing');
        $this->assertSame(0, Invoice::count());
    }

    public function test_periods_follow_the_cycle_and_cannot_overlap()
    {
        $next = app(PeriodService::class)->next($this->ws);
        $this->assertSame(['2026-11-01', '2026-11-30'], [$next->starts_on->toDateString(), $next->ends_on->toDateString()]);

        $this->actingAs($this->finance)->post(route('billing.clients.periods.store', $this->ws), [
            'starts_on' => '2026-10-15', 'ends_on' => '2026-11-15',
        ])->assertSessionHasErrors('billing');
    }

    public function test_finance_is_alerted_in_app_when_an_invoice_is_overdue()
    {
        $invoice = $this->service()->finalize($this->draft(), $this->finance);

        $this->travelTo(CarbonImmutable::parse('2026-11-19 08:00:00'));
        $this->artisan('billing:alerts')->assertSuccessful();
        $this->artisan('billing:alerts')->assertSuccessful();

        $this->assertSame(1, InboxNotification::where('notifiable_id', $this->finance->id)->where('kind', 'invoice_overdue')->count());
        $this->assertSame(0, InboxNotification::where('notifiable_id', $this->max->id)->count());
        $this->assertNotNull($invoice->fresh()->overdue_alerted_at);
    }
}
