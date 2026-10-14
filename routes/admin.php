<?php

use App\Domain\Identity\Permissions;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\ApiTokenAdminController;
use App\Http\Controllers\Admin\CompanySettingsController;
use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\FailedJobController;
use App\Http\Controllers\Admin\FileSettingsController;
use App\Http\Controllers\Admin\HolidayController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SystemHealthController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\UserImportController;
use App\Http\Controllers\Admin\ViewAsController;
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
        Route::get('users/export', [UserImportController::class, 'export'])->name('users.csv');
    });

    Route::middleware('can:'.Permissions::USERS_MANAGE)->group(function () {
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
        Route::post('users/import', [UserImportController::class, 'import'])->name('users.bulk-import');
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::post('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('users/{user}/reactivate', [UserController::class, 'reactivate'])->name('users.reactivate');
        Route::post('users/{user}/access-link', [UserController::class, 'issueAccessLink'])->name('users.access-link');
        Route::post('users/{user}/temporary-password', [UserController::class, 'temporaryPassword'])->name('users.temporary-password');
        Route::post('users/{user}/sign-out', [UserController::class, 'signOut'])->name('users.sign-out');
        Route::delete('users/{user}/sessions/{userSession}', [UserController::class, 'destroySession'])->name('users.sessions.destroy');
        Route::get('users/{user}/personal-data', [UserController::class, 'exportData'])->name('users.export-data');
        Route::post('users/{user}/anonymise', [UserController::class, 'anonymise'])->name('users.anonymise');
        Route::post('users/{user}/view-as', [ViewAsController::class, 'start'])->name('users.view-as'); // E-90 (super admins; checked in ViewAs)
    });

    // Roles & permissions
    Route::middleware('can:'.Permissions::ROLES_MANAGE)->group(function () {
        Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    // Departments
    Route::middleware('can:'.Permissions::DEPARTMENTS_MANAGE)->group(function () {
        Route::get('departments', [DepartmentController::class, 'index'])->name('departments.index');
        Route::post('departments', [DepartmentController::class, 'store'])->name('departments.store');
        Route::put('departments/{department}', [DepartmentController::class, 'update'])->name('departments.update');
        Route::post('departments/{department}/archive', [DepartmentController::class, 'archive'])->name('departments.archive');
        Route::post('departments/{department}/restore', [DepartmentController::class, 'restore'])->name('departments.restore');
    });

    // Audit log
    Route::get('activity', [ActivityLogController::class, 'index'])
        ->middleware('can:'.Permissions::ACTIVITY_VIEW)
        ->name('activity.index');
    Route::get('activity/export', [ActivityLogController::class, 'export'])
        ->middleware('can:'.Permissions::ACTIVITY_VIEW)
        ->name('activity.csv');

    // System (E-89: each part has its own permission; system.manage includes all).
    Route::middleware('can:'.Permissions::SYSTEM_JOBS)->group(function () {
        Route::get('failed-jobs', [FailedJobController::class, 'index'])->name('failed-jobs.index');
        Route::post('failed-jobs/retry', [FailedJobController::class, 'retry'])->name('failed-jobs.retry');
        Route::post('failed-jobs/discard', [FailedJobController::class, 'destroy'])->name('failed-jobs.destroy');
    });
    Route::middleware('can:'.Permissions::SYSTEM_HOLIDAYS)->group(function () {
        Route::get('holidays', [HolidayController::class, 'index'])->name('holidays.index');
        Route::post('holidays', [HolidayController::class, 'store'])->name('holidays.store');
        Route::post('holidays/import', [HolidayController::class, 'import'])->name('holidays.bulk'); // "import" is a JS keyword
        Route::delete('holidays/{holiday}', [HolidayController::class, 'destroy'])->name('holidays.destroy');
    });
    Route::get('health', SystemHealthController::class)->middleware('can:'.Permissions::SYSTEM_HEALTH)->name('health');
    // E-97: every API token in the agency (owner, abilities, last used) with revoke.
    Route::middleware('can:'.Permissions::USERS_MANAGE)->group(function () {
        Route::get('api-tokens', [ApiTokenAdminController::class, 'index'])->name('api-tokens.index');
        Route::delete('api-tokens/{token}', [ApiTokenAdminController::class, 'destroy'])->whereNumber('token')->name('api-tokens.destroy');
    });
    Route::middleware('can:'.Permissions::SYSTEM_FILES)->group(function () {
        Route::get('files', [FileSettingsController::class, 'edit'])->name('files.edit');
        Route::put('files', [FileSettingsController::class, 'update'])->name('files.update');
    });

    // E-92: company details and logo on documents (system admins and Finance).
    Route::middleware('can:manage-company')->group(function () {
        Route::get('company', [CompanySettingsController::class, 'edit'])->name('company.edit');
        Route::put('company', [CompanySettingsController::class, 'update'])->name('company.update');
        Route::put('company/finance', [CompanySettingsController::class, 'updateFinance'])->name('company.finance');
        Route::put('company/payment-accounts', [CompanySettingsController::class, 'updateAccounts'])->name('company.payment-accounts');
        Route::post('company/logo', [CompanySettingsController::class, 'uploadLogo'])->name('company.logo.store');
        Route::delete('company/logo', [CompanySettingsController::class, 'removeLogo'])->name('company.logo.destroy');
        Route::get('company/logo', [CompanySettingsController::class, 'logo'])->name('company.logo');
    });
});

// E-90: leave view-as (allowed while viewing as someone; acts as the admin).
Route::post('view-as/stop', [ViewAsController::class, 'stop'])->middleware('auth')->name('view-as.stop');
