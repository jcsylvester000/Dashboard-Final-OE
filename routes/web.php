<?php

use App\Http\Controllers\Auth\AccessLinkController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InboxController;
use Illuminate\Support\Facades\Route;

// Internal app: there is no public landing page.
Route::redirect('/', '/dashboard')->name('home');

// One-time account setup / password reset links issued by an admin (no email).
Route::middleware('throttle:access-links')->group(function () {
    Route::get('access/{token}', [AccessLinkController::class, 'show'])->name('access-link.show');
    Route::post('access/{token}', [AccessLinkController::class, 'store'])->name('access-link.store');
});

Route::middleware('auth')->group(function () {
    Route::get('password/change', [ForcePasswordChangeController::class, 'edit'])->name('password.force.edit');
    Route::put('password/change', [ForcePasswordChangeController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('password.force.update');

    Route::get('dashboard', DashboardController::class)->name('dashboard');

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
require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
