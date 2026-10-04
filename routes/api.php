<?php

use App\Http\Controllers\Api\V1\CommentController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\TaskController;
use App\Http\Controllers\Api\V1\WorkspaceController;
use App\Http\Controllers\Api\V1\WorkSummaryController;
use App\Http\Middleware\Idempotency;
use Illuminate\Support\Facades\Route;

/*
| API v1 (P7) for the mobile app and integrations. Bearer tokens from Settings > API tokens.
| Abilities: read (GET), write (changes). Same policies and workspace scoping as the web app.
| POSTs accept an Idempotency-Key header. Rate limited per token (config api.per_minute).
*/
Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', 'throttle:api', Idempotency::class])->group(function () {
    Route::middleware('ability:read,write')->group(function () {
        Route::get('me', MeController::class)->name('me');

        Route::get('workspaces', [WorkspaceController::class, 'index'])->name('workspaces.index');
        Route::get('workspaces/{workspace:id}', [WorkspaceController::class, 'show'])->name('workspaces.show');
        Route::get('workspaces/{workspace:id}/members', [WorkspaceController::class, 'members'])->name('workspaces.members');
        Route::get('workspaces/{workspace:id}/projects', [WorkspaceController::class, 'projects'])->name('workspaces.projects');
        Route::get('workspaces/{workspace:id}/work-summary', WorkSummaryController::class)->name('workspaces.work-summary');

        Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
        Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
        Route::get('tasks/{task}/comments', [CommentController::class, 'index'])->name('tasks.comments.index');

        Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    });

    Route::middleware('abilities:write')->group(function () {
        Route::post('workspaces/{workspace:id}/tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::patch('workspaces/{workspace:id}/tasks/{task}', [TaskController::class, 'update'])->scopeBindings()->name('tasks.update');
        Route::post('tasks/{task}/comments', [CommentController::class, 'store'])->name('tasks.comments.store');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereUuid('notification')->name('notifications.read');
    });
});
