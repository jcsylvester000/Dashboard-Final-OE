<?php

use App\Http\Controllers\Workspaces\DeliverySpecController;
use App\Http\Controllers\Workspaces\IntegrationsController;
use App\Http\Controllers\Workspaces\LabelController;
use App\Http\Controllers\Workspaces\ProjectBudgetController;
use App\Http\Controllers\Workspaces\ProjectController;
use App\Http\Controllers\Workspaces\RecordLinkController;
use App\Http\Controllers\Workspaces\ReviewRuleController;
use App\Http\Controllers\Workspaces\TaskFieldsController;
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
        // B-07: custom task fields and status names (leads).
        Route::put('settings/task-fields', [TaskFieldsController::class, 'fields'])->name('task-fields.update');
        Route::put('settings/status-labels', [TaskFieldsController::class, 'statusLabels'])->name('status-labels.update');
        Route::put('settings/checklists', [TaskFieldsController::class, 'checklists'])->name('checklists.update');
        // E-34: default reviewers (leads).
        Route::post('settings/review-rules', [ReviewRuleController::class, 'store'])->name('review-rules.store');
        Route::delete('settings/review-rules/{reviewRule}', [ReviewRuleController::class, 'destroy'])->name('review-rules.destroy');
        // E-18: delivery specs videos are checked against (leads).
        Route::post('settings/delivery-specs', [DeliverySpecController::class, 'store'])->name('delivery-specs.store');
        Route::put('settings/delivery-specs/{deliverySpec}', [DeliverySpecController::class, 'update'])->name('delivery-specs.update');
        Route::delete('settings/delivery-specs/{deliverySpec}', [DeliverySpecController::class, 'destroy'])->name('delivery-specs.destroy');
        // E-24 / E-41: GitHub repositories and chat channels (leads).
        Route::post('settings/repositories', [IntegrationsController::class, 'storeRepository'])->name('repositories.store');
        Route::put('settings/repositories/{repository}', [IntegrationsController::class, 'updateRepository'])->name('repositories.update');
        Route::delete('settings/repositories/{repository}', [IntegrationsController::class, 'destroyRepository'])->name('repositories.destroy');
        Route::post('settings/chat-channels', [IntegrationsController::class, 'storeChannel'])->name('chat-channels.store');
        Route::put('settings/chat-channels/{channel}', [IntegrationsController::class, 'updateChannel'])->name('chat-channels.update');
        Route::delete('settings/chat-channels/{channel}', [IntegrationsController::class, 'destroyChannel'])->name('chat-channels.destroy');
        Route::post('settings/chat-channels/{channel}/test', [IntegrationsController::class, 'testChannel'])->middleware('throttle:10,1')->name('chat-channels.test');
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
            Route::post('projects/{project}/status-link', [ProjectController::class, 'statusLink'])->name('projects.status-link');
            Route::put('projects/{project}/status-detail', [ProjectController::class, 'statusDetail'])->name('projects.status-detail');
            Route::delete('projects/{project}/status-link', [ProjectController::class, 'disableStatusLink'])->name('projects.status-link.destroy');
            Route::put('projects/{project}/budget', [ProjectBudgetController::class, 'update'])->name('projects.budget'); // E-76
        });
    });

    // Cross-references between records (any workspace the user can see).
    Route::post('links', [RecordLinkController::class, 'store'])->name('links.store');
    Route::delete('links/{link}', [RecordLinkController::class, 'destroy'])->name('links.destroy');
    Route::get('references', [RecordLinkController::class, 'search'])
        ->middleware('throttle:60,1')
        ->name('references.search');
});
