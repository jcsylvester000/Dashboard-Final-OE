<?php

namespace Tests\Feature\Billing;

use App\Domain\Billing\InvoicePdf;
use App\Domain\Billing\Money;
use App\Domain\Settings\CompanyProfile;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Enhancement batch E3: company details + logo (E-92/E-47), branded invoice PDF with
 * VAT label, balance and amount in words (E-80), ISO codes for foreign currency (E-102),
 * page-break rules and page numbers (E-43..E-48).
 */
class EnhancementBatchE3Test extends TestCase
{
    use RefreshDatabase;

    /** A real 1x1 PNG (no GD needed). */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    private User $finance;

    private Workspace $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RolesAndPermissionsSeeder::class, DepartmentSeeder::class]);
        Storage::fake('local');
        $this->travelTo('2026-10-14 09:00:00');
        $this->finance = User::factory()->withRole('finance')->create();
        $this->ws = Workspace::factory()->create(['name' => 'Berlin Bakery']);
    }

    private function companyForm(array $overrides = []): array
    {
        return [
            'name' => 'OverEasy', 'legal_name' => 'OverEasy Media Solutions Inc.', 'address' => "1 Ayala Ave\nMakati",
            'tax_id' => '123-456-789', 'branch_code' => '00000', 'vat_registered' => true, 'contact' => 'billing@overeasy.test',
            'brand_color' => '#f59e0b', 'invoice_footer' => 'Thank you!', ...$overrides,
        ];
    }

    private function invoice(string $currency, int $lines, int $paid = 0): Invoice
    {
        $invoice = Invoice::create(['workspace_id' => $this->ws->id, 'series' => 'INV', 'currency' => $currency, 'tax_rate_bp' => 1200,
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31', 'bill_to' => ['name' => 'Berlin Bakery GmbH']]);
        for ($i = 0; $i < $lines; $i++) {
            $invoice->items()->create(['type' => 'hours', 'description' => 'Design work, round '.($i + 1).' with a longer description that wraps onto a second line in the PDF table',
                'quantity' => '1.00', 'unit_minor' => 150000, 'amount_minor' => 150000, 'position' => $i]);
        }
        $subtotal = 150000 * $lines;
        $tax = Money::tax($subtotal, 1200);
        $invoice->forceFill(['number' => 7, 'status' => Invoice::FINALIZED, 'issue_date' => '2026-10-14', 'due_date' => '2026-10-29',
            'subtotal_minor' => $subtotal, 'tax_minor' => $tax, 'total_minor' => $subtotal + $tax, 'paid_minor' => $paid])->save();

        return $invoice->fresh() ?? $invoice;
    }

    public function test_finance_manages_company_details_and_logo_members_cannot()
    {
        $member = User::factory()->withRole('member')->create();
        $this->actingAs($member)->get(route('admin.company.edit'))->assertForbidden();
        $this->actingAs($member)->put(route('admin.company.update'), $this->companyForm())->assertForbidden();

        $this->actingAs($this->finance)->put(route('admin.company.update'), $this->companyForm())->assertSessionHasNoErrors();
        $this->actingAs($this->finance)->put(route('admin.company.update'), $this->companyForm(['brand_color' => 'orange']))->assertSessionHasErrors('brand_color');

        $logo = UploadedFile::fake()->createWithContent('logo.png', (string) base64_decode(self::PNG));
        $this->actingAs($this->finance)->post(route('admin.company.logo.store'), ['logo' => $logo])->assertSessionHasNoErrors();
        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $this->actingAs($this->finance)->post(route('admin.company.logo.store'), ['logo' => $svg])->assertSessionHasErrors('logo');

        $company = app(CompanyProfile::class);
        $this->assertSame('OverEasy Media Solutions Inc.', $company->get()['legal_name']);
        $this->assertTrue($company->get()['vat_registered']);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $company->logoDataUri());
        $this->actingAs($this->finance)->get(route('admin.company.edit'))
            ->assertInertia(fn ($page) => $page->where('company.has_logo', true)->where('company.tax_id', '123-456-789'));

        $this->actingAs($this->finance)->delete(route('admin.company.logo.destroy'));
        $this->assertNull(app(CompanyProfile::class)->logoDataUri());
    }

    public function test_invoice_document_is_branded_with_vat_balance_words_and_iso_codes()
    {
        $this->actingAs($this->finance)->put(route('admin.company.update'), $this->companyForm());
        $this->actingAs($this->finance)->post(route('admin.company.logo.store'), ['logo' => UploadedFile::fake()->createWithContent('logo.png', (string) base64_decode(self::PNG))]);
        $company = app(CompanyProfile::class);
        $invoice = $this->invoice('USD', 2, paid: 100000);
        $invoice->load('items', 'workspace');

        $html = view('pdf.invoice', ['invoice' => $invoice, 'company' => $company->get(), 'logo' => $company->logoDataUri(),
            'paymentInstructions' => 'Wire to USD account', 'draft' => false])->render();

        $this->assertStringContainsString('data:image/png;base64,', $html);
        $this->assertStringContainsString('OverEasy Media Solutions Inc.', $html);
        $this->assertStringContainsString('VAT (12.00%)', $html);
        $this->assertStringContainsString('USD 3,000.00', $html);  // subtotal with ISO code
        $this->assertStringContainsString('Balance due', $html);
        $this->assertStringContainsString('Three thousand three hundred sixty US dollars and 00/100', $html);
        $this->assertStringContainsString('class="section"', $html);
        $this->assertStringContainsString('page-break-inside: avoid', $html);
        $this->assertStringContainsString('Thank you!', $html);
    }

    public function test_long_invoices_paginate_and_number_their_pages()
    {
        $pdf = app(InvoicePdf::class)->invoice($this->invoice('PHP', 120));

        $this->assertStringStartsWith('%PDF', $pdf);
        $pages = preg_match_all('#/Type\s*/Page[^s]#', $pdf);
        $this->assertGreaterThanOrEqual(3, $pages, 'a 120-line invoice spans several pages');
    }

    public function test_amounts_in_words()
    {
        $this->assertSame('One thousand five hundred pesos and 50/100', Money::inWords(150050, 'PHP'));
        $this->assertSame('Twelve million three hundred forty-five thousand six hundred seventy-eight euros and 09/100', Money::inWords(1234567809, 'EUR'));
        $this->assertSame('Zero US dollars and 05/100', Money::inWords(5, 'USD'));
        $this->assertSame('₱1,500.50', Money::formatForDocument(150050, 'PHP'));
        $this->assertSame('EUR 1,500.50', Money::formatForDocument(150050, 'EUR'));
    }

    public function test_status_page_shows_the_agency_logo()
    {
        $this->actingAs($this->finance)->post(route('admin.company.logo.store'), ['logo' => UploadedFile::fake()->createWithContent('logo.png', (string) base64_decode(self::PNG))]);
        $project = Project::factory()->create(['workspace_id' => $this->ws->id, 'status_token' => str_repeat('z', 40)]);
        auth()->logout();

        $this->get(route('project-status.show', $project->status_token))->assertOk()->assertSee('data:image/png;base64,', false);
    }
}
