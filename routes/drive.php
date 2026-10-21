<?php

use App\Http\Controllers\ClientDocumentController;
use App\Http\Controllers\ClientReviewController;
use App\Http\Controllers\DriveController;
use App\Http\Controllers\IntakeController;
use App\Http\Controllers\ProjectStatusController;
use App\Http\Controllers\ShareController;
use App\Http\Controllers\Work\ReleaseNotesController;
use Illuminate\Support\Facades\Route;

/*
| Team file drive ("My files") on UploadThing, and public share links for clients.
*/
Route::middleware('auth')->prefix('drive')->name('drive.')->group(function () {
    Route::get('/', [DriveController::class, 'index'])->name('index');
    Route::get('picker', [DriveController::class, 'picker'])->name('picker');
    Route::post('uploads', [DriveController::class, 'prepare'])->middleware('throttle:60,1')->name('prepare');
    Route::post('{file}/complete', [DriveController::class, 'complete'])->middleware('throttle:60,1')->name('complete');
    Route::post('{file}/cancel', [DriveController::class, 'cancel'])->middleware('throttle:60,1')->name('cancel');
    Route::get('{file}/open', [DriveController::class, 'open'])->middleware('throttle:240,1')->name('open');
    Route::put('{file}', [DriveController::class, 'update'])->name('update');
    Route::post('{file}/lock', [DriveController::class, 'lock'])->name('lock');
    Route::post('{file}/link', [DriveController::class, 'regenerateLink'])->name('link');
    Route::delete('{file}', [DriveController::class, 'destroy'])->name('destroy');
});

// Public links: no login, throttled, token-only.
Route::middleware('throttle:60,1')->group(function () {
    Route::get('s/{token}', [ShareController::class, 'show'])->name('share.show');
    // B-06: read-only client status page per project.
    Route::get('p/{token}', ProjectStatusController::class)->name('project-status.show');
    Route::get('s/{token}/file', [ShareController::class, 'file'])->name('share.file');
    // E-70 / E-69: client invoice and statement links.
    Route::get('i/{token}', [ClientDocumentController::class, 'invoice'])->name('invoice-link.show');
    Route::get('i/{token}/pdf', [ClientDocumentController::class, 'invoicePdf'])->name('invoice-link.pdf');
    Route::get('st/{token}', [ClientDocumentController::class, 'statement'])->name('statement-link.show');
    Route::get('st/{token}/pdf', [ClientDocumentController::class, 'statementPdf'])->name('statement-link.pdf');
    // E-08: client review of one file version.
    Route::get('r/{token}', [ClientReviewController::class, 'show'])->name('client-review.show');
    // E-27: shared sprint release notes.
    Route::get('rn/{token}', [ReleaseNotesController::class, 'shared'])->name('release-notes.shared');
    // E-42: public intake forms.
    Route::get('f/{token}', [IntakeController::class, 'show'])->name('intake.show');
    Route::post('f/{token}', [IntakeController::class, 'submit'])->middleware('throttle:5,1')->name('intake.submit');
    Route::get('f/{token}/thanks', [IntakeController::class, 'thanks'])->name('intake.thanks');
    Route::get('r/{token}/file', [ClientReviewController::class, 'file'])->name('client-review.file');
    Route::post('r/{token}/name', [ClientReviewController::class, 'setName'])->name('client-review.name');
    Route::post('r/{token}/comments', [ClientReviewController::class, 'comment'])->middleware('throttle:20,1')->name('client-review.comment');
    Route::post('r/{token}/decision', [ClientReviewController::class, 'decide'])->middleware('throttle:10,1')->name('client-review.decide');
});
