<?php

use App\Domain\Identity\Permissions;
use App\Http\Controllers\Admin\WorkflowTemplateController;
use App\Http\Controllers\Work\CalendarController;
use App\Http\Controllers\Work\CommentController;
use App\Http\Controllers\Work\QueueController;
use App\Http\Controllers\Work\TaskController;
use App\Http\Controllers\Work\TaskFlowController;
use App\Http\Controllers\Work\TimeController;
use Illuminate\Support\Facades\Route;

/*
| Unified cross-department workflow (P3): tasks, board, handoffs,
| dependencies, comments, templates, my tasks, department queues.
*/
Route::middleware('auth')->group(function () {
    Route::get('my-tasks', [QueueController::class, 'mine'])->name('tasks.mine');

    // Time tracking (P3b)
    Route::post('timer/stop', [TimeController::class, 'stop'])->name('time.timer.stop');
    Route::get('timesheet', [TimeController::class, 'timesheet'])->name('time.timesheet');
    Route::get('approvals', [TimeController::class, 'approvals'])->name('time.approvals');
    Route::post('approvals', [TimeController::class, 'approve'])->name('time.approve');
    Route::put('time/{entry}', [TimeController::class, 'update'])->name('time.update');
    Route::delete('time/{entry}', [TimeController::class, 'destroy'])->name('time.destroy');
    Route::get('departments/{department:slug}/queue', [QueueController::class, 'department'])->name('departments.queue');

    Route::prefix('w/{workspace:slug}')->name('workspaces.')->group(function () {
        Route::post('templates/{template}/apply', [TaskFlowController::class, 'applyTemplate'])->name('templates.apply');

        Route::scopeBindings()->group(function () {
            Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
            Route::get('board', [TaskController::class, 'board'])->name('tasks.board');
            Route::get('calendar', CalendarController::class)->name('tasks.calendar');
            Route::post('tasks/{task}/timer', [TimeController::class, 'start'])->name('tasks.timer');
            Route::post('tasks/{task}/time', [TimeController::class, 'log'])->name('tasks.time.store');
            Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
            Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
            Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
            Route::patch('tasks/{task}/move', [TaskController::class, 'move'])->name('tasks.move');
            Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
            Route::post('tasks/{task}/watch', [TaskController::class, 'watch'])->name('tasks.watch');

            Route::post('tasks/{task}/handoff', [TaskFlowController::class, 'handoff'])->name('tasks.handoff');
            Route::post('tasks/{task}/dependencies', [TaskFlowController::class, 'addDependency'])->name('tasks.dependencies.store');
            Route::post('tasks/{task}/comments', [CommentController::class, 'store'])->name('tasks.comments.store');
            Route::put('comments/{comment}', [CommentController::class, 'update'])->name('comments.update');
            Route::delete('comments/{comment}', [CommentController::class, 'destroy'])->name('comments.destroy');
        });

        // {dependency} is another task; checked against the same workspace in the controller via scoped {task}.
        Route::delete('tasks/{task}/dependencies/{dependency}', [TaskFlowController::class, 'removeDependency'])
            ->scopeBindings()
            ->name('tasks.dependencies.destroy');
    });

    Route::middleware('can:'.Permissions::WORKSPACES_MANAGE)->prefix('admin')->name('admin.')->group(function () {
        Route::get('templates', [WorkflowTemplateController::class, 'index'])->name('templates.index');
        Route::post('templates', [WorkflowTemplateController::class, 'store'])->name('templates.store');
        Route::put('templates/{template}', [WorkflowTemplateController::class, 'update'])->name('templates.update');
        Route::delete('templates/{template}', [WorkflowTemplateController::class, 'destroy'])->name('templates.destroy');
    });
});
