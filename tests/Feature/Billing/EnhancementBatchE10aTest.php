<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\InvoiceService;
use App\Domain\Billing\PrepaidHoursService;
use App\Models\BillingPeriod;
use App\Models\BillingProfile;
use App\Models\ClientCredit;
use App\Models\Invoice;
use App\Models\PrepaidHours;
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
 * Enhancement batch E10a (E-74): retainer rollover with cap and expiry, prepaid hour blocks
 * drawn down by invoices (never below zero, given back on void), and deposits.
 */
class EnhancementBatchE10aTest extends TestCase
{
    use RefreshDatabase;

    private User $finance;

    private User $dev;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2027-01-10 09:00:00'));
        $this->finance = User::factory()->withRole('finance')->create();
        $this->dev = User::factory()->withRole('member')->create();
        $this->ws = Workspace::factory()->create(['name' => 'Manila Coffee']);
        BillingProfile::create(['workspace_id' => $this->ws->id, 'currency' => 'PHP', 'retainer_minor' => 5000000, 'included_minutes' => 600, 'tax_rate_bp' => 0])
            ->forceFill(['rollover_enabled' => true, 'rollover_cap_minutes' => 300, 'rollover_months' => 1])->save();
        RateCard::create(['rate_minor' => 200000, 'cost_minor' => 80000, 'effective_from' => '2026-01-01', 'currency' => 'PHP']); // ₱2,000/h
    }

    private function log(string $date, int $minutes): void
    {
        TimeEntry::create(['user_id' => $this->dev->id, 'workspace_id' => $this->ws->id, 'entry_date' => $date, 'minutes' => $minutes, 'is_billable' => true])
            ->forceFill(['approved_at' => now(), 'approved_by' => $this->finance->id])->save();
    }

    private function invoice(string $from, string $to, bool $finalize = true): Invoice
    {
        $period = BillingPeriod::create(['workspace_id' => $this->ws->id, 'starts_on' => $from, 'ends_on' => $to]);
        $service = app(InvoiceService::class);
        $draft = $service->buildDraft($period, $this->finance);

        return $finalize ? $service->finalize($draft, $this->finance) : $draft;
    }

    private function prepaidMinutes(Invoice $invoice): int
    {
        return (int) $invoice->items()->where('type', 'prepaid')->sum('minutes');
    }

    private function hoursMinutes(Invoice $invoice): int
    {
        return (int) $invoice->items()->where('type', 'hours')->sum('minutes');
    }

    public function test_unused_hours_roll_over_with_a_cap_and_are_used_next_period()
    {
        $this->log('2026-10-10', 240); // 4 of 10 included hours used
        $october = $this->invoice('2026-10-01', '2026-10-31');

        $roll = PrepaidHours::query()->where('kind', 'rollover')->firstOrFail();
        $this->assertSame(300, $roll->minutes, '6 h unused, capped at 5 h');
        $this->assertSame('2026-11-01', $roll->starts_on->toDateString());
        $this->assertSame('2026-11-30', $roll->expires_on?->toDateString());
        $this->assertSame($october->id, $roll->source_invoice_id);

        // November: 10 h included + 5 h rolled over cover 15 h; nothing billed at rates.
        $this->log('2026-11-12', 900);
        $november = $this->invoice('2026-11-01', '2026-11-30');
        $this->assertSame(300, $this->prepaidMinutes($november));
        $this->assertSame(0, $this->hoursMinutes($november));
        $this->assertSame(5000000, $november->fresh()?->subtotal_minor, 'only the retainer is charged');
        $this->assertSame([], app(PrepaidHoursService::class)->available($this->ws->id));
    }

    public function test_rolled_over_hours_expire_on_time()
    {
        $this->log('2026-10-10', 240);
        $this->invoice('2026-10-01', '2026-10-31'); // 5 h roll over, usable in November only

        $this->log('2026-12-08', 700); // 10 h included, 1 h 40 over; the November rollover has expired
        $december = $this->invoice('2026-12-01', '2026-12-31');
        $this->assertSame(0, $this->prepaidMinutes($december));
        $this->assertSame(100, $this->hoursMinutes($december));
    }

    public function test_blocks_are_drawn_down_never_below_zero_and_given_back_on_void()
    {
        BillingProfile::query()->where('workspace_id', $this->ws->id)->update(['rollover_enabled' => false]);
        $this->actingAs($this->finance)->post(route('billing.clients.prepaid.store', $this->ws), [
            'hours' => '2', 'amount' => '3000', 'starts_on' => '2026-10-01', 'note' => 'Launch support', 'create_invoice' => true,
        ])->assertSessionHasNoErrors();
        $block = PrepaidHours::query()->where('kind', 'block')->firstOrFail();
        $this->assertSame(120, $block->minutes);
        $this->assertSame(300000, $block->amount_minor);
        $blockInvoice = Invoice::query()->findOrFail($block->invoice_id);
        $this->assertTrue($blockInvoice->isDraft());
        $this->assertSame(300000, $blockInvoice->total_minor);

        // October: 10 h included, 1 h 40 over -> all from the block (20 min left).
        $this->log('2026-10-10', 700);
        $october = $this->invoice('2026-10-01', '2026-10-31');
        $this->assertSame(100, $this->prepaidMinutes($october));
        $this->assertSame(0, $this->hoursMinutes($october));

        // November: 1 h 40 over again -> 20 min from the block, the rest billed at rates.
        $this->log('2026-11-10', 700);
        $november = $this->invoice('2026-11-01', '2026-11-30');
        $this->assertSame(20, $this->prepaidMinutes($november));
        $this->assertSame(80, $this->hoursMinutes($november));
        $this->assertSame([], app(PrepaidHoursService::class)->available($this->ws->id), 'empty, never negative');

        // Voiding November gives its 20 minutes back.
        app(InvoiceService::class)->void($november, $this->finance, 'Wrong period');
        $left = app(PrepaidHoursService::class)->available($this->ws->id);
        $this->assertSame(20, $left[0]['left']);

        // A used block can't be removed; an unused one takes its draft invoice with it.
        $this->actingAs($this->finance)->delete(route('billing.prepaid.destroy', $block))->assertSessionHasErrors('billing');
        $this->actingAs($this->finance)->post(route('billing.clients.prepaid.store', $this->ws), [
            'hours' => '1', 'amount' => '1500', 'starts_on' => '2027-01-01', 'create_invoice' => true,
        ]);
        $spare = PrepaidHours::query()->where('kind', 'block')->latest('id')->firstOrFail();
        $this->actingAs($this->finance)->delete(route('billing.prepaid.destroy', $spare))->assertSessionHasNoErrors();
        $this->assertNull(PrepaidHours::query()->find($spare->id));
        $this->assertNull(Invoice::query()->find($spare->invoice_id));
        $this->assertNotNull(Invoice::query()->find($blockInvoice->id));
    }

    public function test_voiding_the_source_invoice_takes_unused_rollover_back()
    {
        $this->log('2026-10-10', 240);
        $october = $this->invoice('2026-10-01', '2026-10-31');
        $this->assertSame(1, PrepaidHours::query()->count());
        app(InvoiceService::class)->void($october, $this->finance, 'Re-issue');
        $this->assertSame(0, PrepaidHours::query()->count());
    }

    public function test_deposits_pay_the_next_invoice_and_leads_see_hours_only()
    {
        $this->actingAs($this->finance)->post(route('billing.clients.deposits.store', $this->ws), [
            'amount' => '20000', 'received_on' => '2027-01-05', 'method' => 'bank', 'reference' => 'BPI-123',
        ])->assertSessionHasNoErrors();
        $this->assertSame(2000000, ClientCredit::balance($this->ws->id, 'PHP'));
        $this->actingAs($this->finance)->post(route('billing.clients.deposits.store', $this->ws), [
            'amount' => '0', 'received_on' => '2027-01-05', 'method' => 'bank',
        ])->assertSessionHasErrors('billing');

        $this->log('2026-10-10', 240);
        $october = $this->invoice('2026-10-01', '2026-10-31');
        $this->assertSame(2000000, $october->fresh()?->paid_minor, 'the deposit pays ₱20,000 of ₱50,000');
        $this->assertSame(0, ClientCredit::balance($this->ws->id, 'PHP'));

        $lead = User::factory()->withRole('member')->create();
        $member = User::factory()->withRole('member')->create();
        $this->ws->members()->attach([$lead->id => ['role' => Workspace::ROLE_LEAD], $member->id => ['role' => Workspace::ROLE_MEMBER]]);
        $this->actingAs($lead)->get(route('workspaces.show', $this->ws))->assertInertia(fn (Assert $page) => $page
            ->where('prepaid', fn ($rows) => count($rows) === 0) // the October rollover starts in November, which has passed
        );
        PrepaidHours::create(['workspace_id' => $this->ws->id, 'kind' => 'block', 'minutes' => 600, 'amount_minor' => 1000000, 'currency' => 'PHP', 'starts_on' => '2027-01-01']);
        $this->actingAs($lead)->get(route('workspaces.show', $this->ws))->assertInertia(fn (Assert $page) => $page
            ->where('prepaid.0.left_minutes', 600)->missing('prepaid.0.amount_minor'));
        $this->actingAs($member)->get(route('workspaces.show', $this->ws))->assertInertia(fn (Assert $page) => $page->where('prepaid', null));
        $this->actingAs($this->finance)->get(route('billing.clients.show', $this->ws))->assertInertia(fn (Assert $page) => $page
            ->where('prepaid.0.amount_minor', 1000000)->where('profile.rollover_enabled', true)->where('profile.rollover_cap_hours', '5.00'));
    }

    public function test_rollover_settings_save_from_the_profile_form()
    {
        $this->actingAs($this->finance)->put(route('billing.clients.profile', $this->ws), [
            'currency' => 'PHP', 'cycle' => 'monthly', 'retainer' => '50000', 'included_hours' => '10', 'overage_rule' => 'bill',
            'terms_days' => 15, 'tax_rate' => '0', 'invoice_series' => 'INV', 'bill_to' => ['name' => 'Manila Coffee'],
            'rollover_enabled' => true, 'rollover_cap_hours' => '7.5', 'rollover_months' => 3,
        ])->assertSessionHasNoErrors();
        $profile = BillingProfile::query()->where('workspace_id', $this->ws->id)->firstOrFail();
        $this->assertSame(450, $profile->rollover_cap_minutes);
        $this->assertSame(3, $profile->rollover_months);

        $this->actingAs($this->finance)->put(route('billing.clients.profile', $this->ws), [
            'currency' => 'PHP', 'cycle' => 'monthly', 'retainer' => '50000', 'included_hours' => '10', 'overage_rule' => 'bill',
            'terms_days' => 15, 'tax_rate' => '0', 'invoice_series' => 'INV', 'bill_to' => ['name' => 'Manila Coffee'],
        ])->assertSessionHasNoErrors();
        $this->assertSame(450, $profile->fresh()?->rollover_cap_minutes, 'a form without the rollover fields leaves them alone');
    }
}
