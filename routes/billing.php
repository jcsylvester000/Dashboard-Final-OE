<?php

use App\Domain\Identity\Permissions;
use App\Http\Controllers\Billing\BillingReportController;
use App\Http\Controllers\Billing\ClientBillingController;
use App\Http\Controllers\Billing\CostRateController;
use App\Http\Controllers\Billing\ExchangeRateController;
use App\Http\Controllers\Billing\ExpenseController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\PrepaidHoursController;
use App\Http\Controllers\Billing\RateCardController;
use App\Http\Controllers\Billing\RateTemplateController;
use App\Http\Controllers\Billing\StatementController;
use Illuminate\Support\Facades\Route;

/*
| Billing (P6). Finance only: members never see rates or invoice totals.
| Clients receive PDFs and links; nothing here sends email.
|
| E-89: billing.view opens the area; each action needs its own permission
| (billing.manage includes them all, see Permissions::IMPLIED).
*/
Route::middleware(['auth', 'can:'.Permissions::BILLING_VIEW])->prefix('billing')->name('billing.')->group(function () {
    Route::redirect('/', '/billing/invoices');

    // Looking: invoices, PDFs, clients, statements, reports, rates.
    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::get('invoices/{invoice}/work-report', [InvoiceController::class, 'workReport'])->name('invoices.work-report');
    Route::get('clients', [ClientBillingController::class, 'index'])->name('clients.index');
    Route::get('clients/{workspace:slug}', [ClientBillingController::class, 'show'])->name('clients.show');
    Route::get('clients/{workspace:slug}/statement', [StatementController::class, 'pdf'])->name('clients.statement');
    Route::get('expenses/{expense}/receipt', [ExpenseController::class, 'receipt'])->name('expenses.receipt');
    Route::get('reports', BillingReportController::class)->name('reports.index');
    Route::get('reports/download/{report}/{format}', [BillingReportController::class, 'export'])
        ->whereIn('report', array_keys(BillingReportController::EXPORTS))->whereIn('format', ['csv', 'xlsx', 'pdf'])->name('reports.download'); // E-81
    Route::get('rates', [RateCardController::class, 'index'])->name('rates.index');
    Route::get('rates/history', [RateCardController::class, 'history'])->name('rates.history');
    Route::get('rate-templates/{rateTemplate}', [RateTemplateController::class, 'edit'])->name('rate-templates.edit');
    Route::get('fx', [ExchangeRateController::class, 'index'])->name('fx.index'); // E-103
    Route::get('cost-rates', [CostRateController::class, 'index'])->name('cost-rates.index'); // E-77

    // Preparing invoices: periods, drafts and their lines, expenses, fixed fees.
    Route::middleware('can:'.Permissions::BILLING_DRAFT)->group(function () {
        Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
        Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
        Route::post('invoices/{invoice}/recalculate', [InvoiceController::class, 'recalculate'])->name('invoices.recalculate');
        Route::post('invoices/{invoice}/lines', [InvoiceController::class, 'storeLine'])->name('invoices.lines.store');
        Route::post('invoices/{invoice}/discounts', [InvoiceController::class, 'storeDiscount'])->name('invoices.discounts.store');
        Route::delete('invoice-lines/{invoiceItem}', [InvoiceController::class, 'destroyLine'])->name('invoice-lines.destroy');
        Route::post('invoices/{invoice}/review', [InvoiceController::class, 'requestReview'])->name('invoices.review');
        Route::post('clients/{workspace:slug}/periods', [ClientBillingController::class, 'storePeriod'])->name('clients.periods.store');
        Route::post('periods/{period}/lock', [ClientBillingController::class, 'lockPeriod'])->name('periods.lock');
        Route::post('periods/{period}/unlock', [ClientBillingController::class, 'unlockPeriod'])->name('periods.unlock');
        Route::post('periods/{period}/invoice', [ClientBillingController::class, 'invoicePeriod'])->name('periods.invoice');
        Route::post('clients/{workspace:slug}/expenses', [ExpenseController::class, 'store'])->name('clients.expenses.store');
        Route::post('expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
        Route::delete('expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
        Route::put('projects/{project}/fixed-fee', [ClientBillingController::class, 'updateFixedFee'])->name('projects.fixed-fee');
        Route::post('clients/{workspace:slug}/prepaid', [PrepaidHoursController::class, 'store'])->name('clients.prepaid.store'); // E-74
        Route::delete('prepaid/{prepaidHours}', [PrepaidHoursController::class, 'destroy'])->name('prepaid.destroy');
    });

    // Signing off: finalize, send back, void, credit notes, approve expenses.
    Route::middleware('can:'.Permissions::BILLING_FINALIZE)->group(function () {
        Route::post('invoices/{invoice}/finalize', [InvoiceController::class, 'finalize'])->name('invoices.finalize');
        Route::post('invoices/{invoice}/send-back', [InvoiceController::class, 'sendBack'])->name('invoices.send-back');
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
        Route::post('invoices/{invoice}/credit', [InvoiceController::class, 'credit'])->name('invoices.credit');
        Route::post('expenses/{expense}/approve', [ExpenseController::class, 'approve'])->name('expenses.approve');
        Route::post('invoices/{invoice}/lock-rate', [InvoiceController::class, 'lockRate'])->name('invoices.lock-rate'); // E-104
    });

    // Collecting: payments, reversals, follow-ups, client links.
    Route::middleware('can:'.Permissions::BILLING_PAYMENTS)->group(function () {
        Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'payment'])->name('invoices.payments.store');
        Route::post('payments/{payment}/reverse', [InvoiceController::class, 'reversePayment'])->name('payments.reverse');
        Route::post('invoices/{invoice}/follow-ups', [InvoiceController::class, 'followUp'])->name('invoices.follow-ups.store');
        Route::post('invoices/{invoice}/link', [InvoiceController::class, 'shareLink'])->name('invoices.link');
        Route::delete('invoices/{invoice}/link', [InvoiceController::class, 'revokeLink'])->name('invoices.unlink');
        Route::post('clients/{workspace:slug}/statement-link', [StatementController::class, 'link'])->name('clients.statement-link');
        Route::delete('clients/{workspace:slug}/statement-link', [StatementController::class, 'unlink'])->name('clients.statement-unlink');
        Route::post('clients/{workspace:slug}/deposits', [PrepaidHoursController::class, 'deposit'])->name('clients.deposits.store'); // E-74
    });

    // Setting up: client billing profiles, rates, rate templates.
    Route::middleware('can:'.Permissions::BILLING_SETTINGS)->group(function () {
        Route::put('clients/{workspace:slug}/profile', [ClientBillingController::class, 'updateProfile'])->name('clients.profile');
        Route::post('rates', [RateCardController::class, 'store'])->name('rates.store');
        Route::put('rates/{rateCard}', [RateCardController::class, 'update'])->name('rates.update');
        Route::delete('rates/{rateCard}', [RateCardController::class, 'destroy'])->name('rates.destroy');
        Route::post('rate-templates', [RateTemplateController::class, 'store'])->name('rate-templates.store');
        Route::put('rate-templates/{rateTemplate}', [RateTemplateController::class, 'update'])->name('rate-templates.update');
        Route::delete('rate-templates/{rateTemplate}', [RateTemplateController::class, 'destroy'])->name('rate-templates.destroy');
        Route::post('clients/{workspace:slug}/rate-template', [RateTemplateController::class, 'apply'])->name('clients.rate-template');
        Route::post('fx', [ExchangeRateController::class, 'store'])->name('fx.store'); // E-103
        Route::post('cost-rates', [CostRateController::class, 'store'])->name('cost-rates.store'); // E-77
    });
});
