<?php

use App\Domain\Identity\Permissions;
use App\Http\Controllers\Reports\AgencyController;
use App\Http\Controllers\Reports\ReportController;
use App\Http\Controllers\Workspaces\ReportSnapshotController;
use App\Http\Controllers\Workspaces\WorkSummaryController;
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

    // Work Summary (P6-15): who worked on what, by task; CSV / PDF export.
    Route::get('w/{workspace:slug}/work-summary', [WorkSummaryController::class, 'index'])->name('workspaces.work-summary.index');
    Route::get('w/{workspace:slug}/work-summary/csv', [WorkSummaryController::class, 'csv'])->name('workspaces.work-summary.csv');
    Route::get('w/{workspace:slug}/work-summary/pdf', [WorkSummaryController::class, 'pdf'])->name('workspaces.work-summary.pdf');
});
