<?php

use App\Domain\Identity\Permissions;
use App\Http\Controllers\Billing\BillingReportController;
use App\Http\Controllers\Billing\ClientBillingController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\RateCardController;
use Illuminate\Support\Facades\Route;

/*
| Billing (P6). Finance only (billing.manage): members never see rates or invoice totals.
| Clients receive PDFs; nothing here sends email.
*/
Route::middleware(['auth', 'can:'.Permissions::BILLING_MANAGE])->prefix('billing')->name('billing.')->group(function () {
    Route::redirect('/', '/billing/invoices');

    Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');
    Route::get('invoices/{invoice}/work-report', [InvoiceController::class, 'workReport'])->name('invoices.work-report');
    Route::put('invoices/{invoice}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::delete('invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('invoices.destroy');
    Route::post('invoices/{invoice}/recalculate', [InvoiceController::class, 'recalculate'])->name('invoices.recalculate');
    Route::post('invoices/{invoice}/finalize', [InvoiceController::class, 'finalize'])->name('invoices.finalize');
    Route::post('invoices/{invoice}/void', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::post('invoices/{invoice}/credit', [InvoiceController::class, 'credit'])->name('invoices.credit');
    Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'payment'])->name('invoices.payments.store');

    Route::get('clients', [ClientBillingController::class, 'index'])->name('clients.index');
    Route::get('clients/{workspace:slug}', [ClientBillingController::class, 'show'])->name('clients.show');
    Route::put('clients/{workspace:slug}/profile', [ClientBillingController::class, 'updateProfile'])->name('clients.profile');
    Route::post('clients/{workspace:slug}/periods', [ClientBillingController::class, 'storePeriod'])->name('clients.periods.store');
    Route::post('clients/{workspace:slug}/expenses', [ClientBillingController::class, 'storeExpense'])->name('clients.expenses.store');

    Route::post('periods/{period}/lock', [ClientBillingController::class, 'lockPeriod'])->name('periods.lock');
    Route::post('periods/{period}/unlock', [ClientBillingController::class, 'unlockPeriod'])->name('periods.unlock');
    Route::post('periods/{period}/invoice', [ClientBillingController::class, 'invoicePeriod'])->name('periods.invoice');
    Route::delete('expenses/{expense}', [ClientBillingController::class, 'destroyExpense'])->name('expenses.destroy');
    Route::put('projects/{project}/fixed-fee', [ClientBillingController::class, 'updateFixedFee'])->name('projects.fixed-fee');

    Route::get('reports', BillingReportController::class)->name('reports.index');

    Route::get('rates', [RateCardController::class, 'index'])->name('rates.index');
    Route::post('rates', [RateCardController::class, 'store'])->name('rates.store');
    Route::delete('rates/{rateCard}', [RateCardController::class, 'destroy'])->name('rates.destroy');
});
