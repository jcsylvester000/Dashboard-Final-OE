<?php

use App\Http\Controllers\Workspaces\LabelController;
use App\Http\Controllers\Workspaces\ProjectController;
use App\Http\Controllers\Workspaces\RecordLinkController;
use App\Http\Controllers\Workspaces\WorkspaceController;
use App\Http\Controllers\Workspaces\WorkspaceMemberController;
use Illuminate\Support\Facades\Route;

/*
| Client workspaces (P2). Every controller action authorizes against the
| user's role in the workspace (see App\Domain\Workspaces\WorkspaceAccess).
*/
Route::middleware('auth')->group(function () {
    Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
    Route::post('workspaces', [WorkspaceController::class, 'store'])->name('workspaces.store');

    Route::prefix('w/{workspace:slug}')->name('workspaces.')->group(function () {
        Route::get('/', [WorkspaceController::class, 'show'])->name('show');
        Route::get('settings', [WorkspaceController::class, 'edit'])->name('edit');
        Route::put('/', [WorkspaceController::class, 'update'])->name('update');

        Route::get('members', [WorkspaceMemberController::class, 'index'])->name('members.index');
        Route::post('members', [WorkspaceMemberController::class, 'store'])->name('members.store');
        Route::put('members/{user}', [WorkspaceMemberController::class, 'update'])->name('members.update');
        Route::delete('members/{user}', [WorkspaceMemberController::class, 'destroy'])->name('members.destroy');

        // Child records must belong to the workspace in the URL.
        Route::scopeBindings()->group(function () {
            Route::post('labels', [LabelController::class, 'store'])->name('labels.store');
            Route::put('labels/{label}', [LabelController::class, 'update'])->name('labels.update');
            Route::delete('labels/{label}', [LabelController::class, 'destroy'])->name('labels.destroy');

            Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
            Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
            Route::get('projects/{project}', [ProjectController::class, 'show'])->name('projects.show');
            Route::put('projects/{project}', [ProjectController::class, 'update'])->name('projects.update');
            Route::delete('projects/{project}', [ProjectController::class, 'destroy'])->name('projects.destroy');
        });
    });

    // Cross-references between records (any workspace the user can see).
    Route::post('links', [RecordLinkController::class, 'store'])->name('links.store');
    Route::delete('links/{link}', [RecordLinkController::class, 'destroy'])->name('links.destroy');
    Route::get('references', [RecordLinkController::class, 'search'])
        ->middleware('throttle:60,1')
        ->name('references.search');
});
