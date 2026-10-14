<?php

use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use App\Http\Controllers\Settings\SessionController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    // E-88: end one session, or every other session.
    Route::delete('settings/sessions/{userSession}', [SessionController::class, 'destroy'])->name('sessions.destroy');
    Route::post('settings/sessions/others', [SessionController::class, 'destroyOthers'])->name('sessions.destroy-others');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/Appearance')->name('appearance.edit');

    Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])->name('notifications.edit');
    Route::put('settings/notifications', [NotificationSettingsController::class, 'update'])->name('notifications.update');
    Route::put('settings/notifications/quiet', [NotificationSettingsController::class, 'quiet'])->name('notifications.quiet'); // E-58
    Route::post('settings/notifications/focus', [NotificationSettingsController::class, 'focus'])->name('notifications.focus'); // E-58

    Route::get('settings/api-tokens', [ApiTokenController::class, 'index'])->name('api-tokens.index');
    Route::post('settings/api-tokens', [ApiTokenController::class, 'store'])->middleware('throttle:10,1')->name('api-tokens.store');
    Route::delete('settings/api-tokens/{token}', [ApiTokenController::class, 'destroy'])->whereNumber('token')->name('api-tokens.destroy');
});
