<?php

use App\Http\Controllers\DriveController;
use App\Http\Controllers\ProjectStatusController;
use App\Http\Controllers\ShareController;
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
});
