<?php

use App\Http\Controllers\AnnotationController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AccessLinkController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\PrivacyController;
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
    Route::post('attachments/from-drive', [AttachmentController::class, 'fromDrive'])->middleware('throttle:60,1')->name('attachments.from-drive');
    Route::get('files/{attachment}', [AttachmentController::class, 'show'])->middleware('signed:relative')->name('files.show');

    // In-app alerts inbox (P4)
    Route::get('inbox', [InboxController::class, 'index'])->name('inbox.index');
    Route::post('inbox/read', [InboxController::class, 'read'])->name('inbox.read');
    Route::post('inbox/done', [InboxController::class, 'done'])->name('inbox.done');
    Route::get('inbox/{notification}/open', [InboxController::class, 'open'])->whereUuid('notification')->name('inbox.open');
    Route::post('inbox/{notification}/snooze', [InboxController::class, 'snooze'])->whereUuid('notification')->name('inbox.snooze');
    Route::post('inbox/{notification}/restore', [InboxController::class, 'restore'])->whereUuid('notification')->name('inbox.restore');
});

require __DIR__.'/workspaces.php';
require __DIR__.'/work.php';
require __DIR__.'/reports.php';
require __DIR__.'/billing.php';
require __DIR__.'/drive.php';
require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
