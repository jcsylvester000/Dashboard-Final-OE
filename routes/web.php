<?php

use App\Http\Controllers\Auth\AccessLinkController;
use App\Http\Controllers\Auth\ForcePasswordChangeController;
use App\Http\Controllers\DashboardController;
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
});

require __DIR__.'/workspaces.php';
require __DIR__.'/admin.php';
require __DIR__.'/settings.php';
