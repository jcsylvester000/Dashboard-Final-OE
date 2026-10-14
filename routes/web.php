<?php

use App\Http\Controllers\AnnotationController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AccessLinkController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\ClientReviewLinkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GettingStartedController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\VideoUploadController;
use Illuminate\Support\Facades\Route;

// Internal app: there is no public landing page.
Route::redirect('/', '/dashboard')->name('home');

// One-time account setup / password reset links issued by an admin (no email).
Route::middleware('throttle:access-links')->group(function () {
    Route::get('access/{token}', [AccessLinkController::class, 'show'])->name('access-link.show');
    Route::post('access/{token}', [AccessLinkController::class, 'store'])->name('access-link.store');
});

Route::middleware('auth')->group(function () {
    // E-99: staff privacy notice, DPO contact, retention schedule.
    Route::get('privacy', [PrivacyController::class, 'index'])->name('privacy.index');
    Route::post('privacy/acknowledge', [PrivacyController::class, 'acknowledge'])->name('privacy.acknowledge');

    Route::get('password/change', [ForcePasswordChangeController::class, 'edit'])->name('password.force.edit');
    Route::put('password/change', [ForcePasswordChangeController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.force.update');

    Route::get('dashboard', DashboardController::class)->name('dashboard');
    // E-59: getting-started checklist.
    Route::get('getting-started', [GettingStartedController::class, 'show'])->name('getting-started.show');
    Route::post('getting-started/hide', [GettingStartedController::class, 'hide'])->name('getting-started.hide');
    Route::post('getting-started/reopen', [GettingStartedController::class, 'reopen'])->name('getting-started.reopen');

    // Attachments (P8): uploads, signed downloads, delete.
    Route::post('attachments', [AttachmentController::class, 'store'])->middleware('throttle:60,1')->name('attachments.store');
    Route::delete('attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
    Route::post('attachments/{attachment}/approve', [AttachmentController::class, 'approve'])->name('attachments.approve');
    Route::post('attachments/{attachment}/review', [AttachmentController::class, 'review'])->name('attachments.review');
    // E-04 / E-05: pins, threads and drawings on a file version (JSON).
    Route::get('attachments/{attachment}/annotations', [AnnotationController::class, 'index'])->name('attachments.annotations.index');
    Route::post('attachments/{attachment}/annotations', [AnnotationController::class, 'store'])->middleware('throttle:120,1')->name('attachments.annotations.store');
    Route::patch('annotations/{annotation}', [AnnotationController::class, 'update'])->name('annotations.update');
    Route::delete('annotations/{annotation}', [AnnotationController::class, 'destroy'])->name('annotations.destroy');
    // E-08 / E-09: client review link per version, feedback sheet PDF.
    Route::post('attachments/{attachment}/client-link', [ClientReviewLinkController::class, 'store'])->name('attachments.client-link');
    Route::delete('attachments/{attachment}/client-link', [ClientReviewLinkController::class, 'destroy'])->name('attachments.client-unlink');
    Route::get('attachments/{attachment}/feedback-sheet', [ClientReviewLinkController::class, 'feedbackSheet'])->name('attachments.feedback-sheet');
    Route::post('attachments/from-drive', [AttachmentController::class, 'fromDrive'])->middleware('throttle:60,1')->name('attachments.from-drive');
    Route::get('files/{attachment}', [AttachmentController::class, 'show'])->middleware('signed:relative')->name('files.show');
    // E-11: direct, resumable video uploads (JSON). Parts go straight to storage.
    Route::post('video-uploads', [VideoUploadController::class, 'start'])->middleware('throttle:30,1')->name('video-uploads.start');
    Route::post('video-uploads/{attachment}/parts', [VideoUploadController::class, 'parts'])->middleware('throttle:600,1')->name('video-uploads.parts');
    Route::put('video-uploads/{attachment}/parts/{part}', [VideoUploadController::class, 'part'])->whereNumber('part')
        ->middleware(['signed:relative', 'throttle:1200,1'])->name('video-uploads.part'); // local driver only
    Route::post('video-uploads/{attachment}/complete', [VideoUploadController::class, 'complete'])->name('video-uploads.complete');
    Route::delete('video-uploads/{attachment}', [VideoUploadController::class, 'abort'])->name('video-uploads.abort');

    // In-app alerts inbox (P4)
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::post('inbox/read', [InboxController::class, 'read'])->name('inbox.read');
    Route::post('inbox/done', [InboxController::class, 'done'])->name('inbox.done');
    Route::get('inbox/{notification}/open', [InboxController::class, 'open'])->whereUuid('notification')->name('inbox.open');
    Route::post('inbox/{notification}/snooze', [InboxController::class, 'snooze'])->whereUuid('notification')->name('inbox.snooze');
    Route::post('inbox/{notification}/restore', [InboxController::class, 'restore'])->whereUuid('notification')->name('inbox.restore');
    Route::post('inbox/{notification}/approve', [InboxController::class, 'approve'])->whereUuid('notification')->name('inbox.approve'); // E-38
});

require __DIR__.'/workspaces.php';
require __DIR__.'/work.php';
require __DIR__.'/reports.php';
require __DIR__.'/billing.php';
require __DIR__.'/drive.php';
require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
