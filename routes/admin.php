<?php

use App\Domain\Identity\Permissions;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\FailedJobController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
| Admin area. Every route checks a permission; policies add record-level rules
| (e.g. only a Super Admin can edit another Super Admin).
*/
Route::redirect('admin', '/admin/users');

Route::middleware('auth')->prefix('admin')->name('admin.')->group(function () {

    // Team members
    Route::middleware('can:'.Permissions::USERS_VIEW)->group(function () {
        Route::get('users', [UserController::class, 'index'])->name('users.index');
    });

    Route::middleware('can:'.Permissions::USERS_MANAGE)->group(function () {
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
        Route::post('users/{user}/access-link', [UserController::class, 'issueAccessLink'])->name('users.access-link');
        Route::post('users/{user}/temporary-password', [UserController::class, 'temporaryPassword'])->name('users.temporary-password');
        Route::post('users/{user}/reset-two-factor', [UserController::class, 'resetTwoFactor'])->name('users.reset-two-factor');
        Route::post('users/{user}/sign-out', [UserController::class, 'signOut'])->name('users.sign-out');
    });

    // Roles & permissions
    Route::middleware('can:'.Permissions::ROLES_MANAGE)->group(function () {
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    });

    // Departments
    Route::middleware('can:'.Permissions::DEPARTMENTS_MANAGE)->group(function () {
        Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
    });

    // Audit log
    Route::get('activity', [ActivityLogController::class, 'index'])
        ->middleware('can:'.Permissions::ACTIVITY_VIEW)
        ->name('activity.index');

    // Failed background jobs (P4)
    Route::middleware('can:'.Permissions::SYSTEM_MANAGE)->group(function () {
        Route::get('failed-jobs', [FailedJobController::class, 'index'])->name('failed-jobs.index');
        Route::post('failed-jobs/retry', [FailedJobController::class, 'retry'])->name('failed-jobs.retry');
        Route::post('failed-jobs/discard', [FailedJobController::class, 'destroy'])->name('failed-jobs.destroy');
    });
});
