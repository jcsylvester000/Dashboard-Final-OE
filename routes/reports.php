<?php

use App\Domain\Identity\Permissions;
use App\Http\Controllers\Reports\AgencyController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Workspaces\ReportSnapshotController;
use Illuminate\Support\Facades\Route;

/*
| Dashboards & reports (P5). ReportFilters limits leads to their own workspaces.
*/
Route::middleware('auth')->group(function () {
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/csv', [ReportController::class, 'csv'])->name('reports.csv');

    Route::get('reports/agency', AgencyController::class)
        ->middleware('can:'.Permissions::REPORTS_VIEW_ALL)
        ->name('reports.agency');

    Route::get('w/{workspace:slug}/reports', [ReportSnapshotController::class, 'index'])->name('workspaces.reports.index');
    Route::post('w/{workspace:slug}/reports', [ReportSnapshotController::class, 'store'])->name('workspaces.reports.store');
});
