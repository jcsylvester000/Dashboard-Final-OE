<?php

use App\Domain\Identity\Permissions;
use App\Http\Controllers\Admin\WorkflowTemplateController;
use App\Http\Controllers\People\DirectoryController;
use App\Http\Controllers\People\LeaveController;
use App\Http\Controllers\People\PeopleReportController;
use App\Http\Controllers\People\WorkloadController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\Work\CalendarController;
use App\Http\Controllers\Work\ChecklistController;
use App\Http\Controllers\Work\CitationController;
use App\Http\Controllers\Work\CommentController;
use App\Http\Controllers\Work\FindingController;
use App\Http\Controllers\Work\QueueController;
use App\Http\Controllers\Work\ReleaseNotesController;
use App\Http\Controllers\Work\ReviewQueueController;
use App\Http\Controllers\Work\SavedViewController;
use App\Http\Controllers\Work\SprintController;
use App\Http\Controllers\Work\TaskBulkController;
use App\Http\Controllers\Work\TaskController;
use App\Http\Controllers\Work\TaskDeliverySpecController;
use App\Http\Controllers\Work\TaskFlowController;
use App\Http\Controllers\Work\TaskMuteController;
use App\Http\Controllers\Work\TaskPreviewController;
use App\Http\Controllers\Work\TimeController;
use App\Http\Controllers\Work\TimelineController;
use Illuminate\Support\Facades\Route;

/*
| Unified cross-department workflow (P3): tasks, board, handoffs,
| dependencies, comments, templates, my tasks, department queues.
*/
Route::middleware('auth')->group(function () {
    Route::get('my-tasks', [QueueController::class, 'mine'])->name('tasks.mine');
    // E-32: everything waiting for my review, across clients.
    Route::get('reviews', ReviewQueueController::class)->name('reviews.index');
    // E-31: findings library (across the clients you can see).
    Route::get('findings', [FindingController::class, 'index'])->name('findings.index');
    Route::post('findings', [FindingController::class, 'store'])->middleware('throttle:60,1')->name('findings.store');
    Route::put('findings/{finding}', [FindingController::class, 'update'])->name('findings.update');
    Route::delete('findings/{finding}', [FindingController::class, 'destroy'])->name('findings.destroy');
    Route::post('findings/{finding}/tasks', [FindingController::class, 'link'])->name('findings.link');
    Route::delete('findings/{finding}/tasks/{task}', [FindingController::class, 'unlink'])->name('findings.unlink');
    Route::get('tasks/new-options', [TaskController::class, 'newOptions'])->name('tasks.new-options');

    // People (findings batch 4): leave, workload, people report, directory
    Route::get('leave', [LeaveController::class, 'index'])->name('leave.index');
    Route::post('leave', [LeaveController::class, 'store'])->name('leave.store');
    Route::post('leave/{leave}/decide', [LeaveController::class, 'decide'])->name('leave.decide');
    Route::post('leave/{leave}/cancel', [LeaveController::class, 'cancel'])->name('leave.cancel');
    Route::get('workload', WorkloadController::class)->name('workload');
    Route::get('reports/people', [PeopleReportController::class, 'index'])->name('reports.people');
    Route::get('reports/people/csv', [PeopleReportController::class, 'csv'])->name('reports.people.csv');
    Route::get('reports/people/download/{format}', [PeopleReportController::class, 'export'])->whereIn('format', ['csv', 'xlsx', 'pdf'])->name('reports.people.download'); // E-49
    Route::get('directory', DirectoryController::class)->name('directory');

    // Global search + short task links (Build 8b)
    Route::get('search', SearchController::class)->middleware('throttle:120,1')->name('search');
    Route::get('t/{key}', [SearchController::class, 'open'])->where('key', '[A-Za-z][A-Za-z0-9]{1,5}-[0-9]{1,9}')->name('tasks.by-key');

    // Time tracking (P3b)
    Route::post('timer/stop', [TimeController::class, 'stop'])->name('time.timer.stop');
    Route::get('timesheet', [TimeController::class, 'timesheet'])->name('time.timesheet');
    Route::get('approvals', [TimeController::class, 'approvals'])->name('time.approvals');
    Route::post('approvals', [TimeController::class, 'approve'])->name('time.approve');
    Route::post('approvals/return', [TimeController::class, 'returnEntries'])->name('time.return');
    Route::post('timesheet/submit', [TimeController::class, 'submitWeek'])->name('time.submit');
    Route::put('time/{entry}', [TimeController::class, 'update'])->name('time.update');
    Route::delete('time/{entry}', [TimeController::class, 'destroy'])->name('time.destroy');
    Route::get('departments/{department:slug}/queue', [QueueController::class, 'department'])->name('departments.queue');

    Route::prefix('w/{workspace:slug}')->name('workspaces.')->group(function () {
        Route::post('templates/{template}/apply', [TaskFlowController::class, 'applyTemplate'])->name('templates.apply');

        Route::scopeBindings()->group(function () {
            Route::get('tasks', [TaskController::class, 'index'])->name('tasks.index');
            Route::get('board', [TaskController::class, 'board'])->name('tasks.board');
            Route::get('calendar', CalendarController::class)->name('tasks.calendar');
            Route::get('timeline', TimelineController::class)->name('tasks.timeline');
            Route::post('tasks/{task}/timer', [TimeController::class, 'start'])->name('tasks.timer');
            Route::post('tasks/{task}/time', [TimeController::class, 'log'])->name('tasks.time.store');
            Route::post('tasks', [TaskController::class, 'store'])->name('tasks.store');
            Route::post('tasks/bulk', TaskBulkController::class)->name('tasks.bulk');

            // Saved views (Build 8d)
            Route::post('views', [SavedViewController::class, 'store'])->name('views.store');
            Route::delete('views/{view}', [SavedViewController::class, 'destroy'])->name('views.destroy');

            // Sprints (Build 8c)
            Route::get('sprints', [SprintController::class, 'index'])->name('sprints.index');
            Route::post('sprints', [SprintController::class, 'store'])->name('sprints.store');
            Route::put('sprints/{sprint}', [SprintController::class, 'update'])->name('sprints.update');
            Route::post('sprints/{sprint}/start', [SprintController::class, 'start'])->name('sprints.start');
            Route::post('sprints/{sprint}/close', [SprintController::class, 'close'])->name('sprints.close');
            Route::delete('sprints/{sprint}', [SprintController::class, 'destroy'])->name('sprints.destroy');
            // E-27: release notes.
            Route::get('sprints/{sprint}/release-notes', [ReleaseNotesController::class, 'show'])->name('sprints.release-notes');
            Route::put('sprints/{sprint}/release-notes', [ReleaseNotesController::class, 'update'])->name('sprints.release-notes.update');
            Route::post('sprints/{sprint}/release-notes/redraft', [ReleaseNotesController::class, 'redraft'])->name('sprints.release-notes.redraft');
            Route::get('sprints/{sprint}/release-notes/pdf', [ReleaseNotesController::class, 'pdf'])->name('sprints.release-notes.pdf');
            Route::post('sprints/{sprint}/release-notes/share', [ReleaseNotesController::class, 'share'])->name('sprints.release-notes.share');
            Route::delete('sprints/{sprint}/release-notes/share', [ReleaseNotesController::class, 'unshare'])->name('sprints.release-notes.unshare');
            Route::get('tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
            Route::put('tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
            Route::patch('tasks/{task}/move', [TaskController::class, 'move'])->name('tasks.move');
            Route::delete('tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
            Route::post('tasks/{task}/watch', [TaskController::class, 'watch'])->name('tasks.watch');
            Route::post('tasks/{task}/duplicate', [TaskController::class, 'duplicate'])->name('tasks.duplicate');

            Route::post('tasks/{task}/handoff', [TaskFlowController::class, 'handoff'])->name('tasks.handoff');
            Route::post('tasks/{task}/review', [TaskFlowController::class, 'review'])->name('tasks.review');
            Route::put('tasks/{task}/reviewers', [TaskFlowController::class, 'reviewers'])->name('tasks.reviewers');
            Route::post('tasks/{task}/checklist', [ChecklistController::class, 'store'])->name('tasks.checklist.store');
            Route::patch('tasks/{task}/checklist/{checklistItem}', [ChecklistController::class, 'update'])->name('tasks.checklist.update');
            Route::delete('tasks/{task}/checklist/{checklistItem}', [ChecklistController::class, 'destroy'])->name('tasks.checklist.destroy');
            Route::post('tasks/{task}/dependencies', [TaskFlowController::class, 'addDependency'])->name('tasks.dependencies.store');
            Route::post('tasks/{task}/comments', [CommentController::class, 'store'])->name('tasks.comments.store');
            Route::post('tasks/{task}/mute', [TaskMuteController::class, 'store'])->name('tasks.mute'); // E-58
            Route::post('tasks/{task}/citations', [CitationController::class, 'store'])->middleware('throttle:30,1')->name('tasks.citations.store'); // E-29
            Route::put('tasks/{task}/delivery-spec', [TaskDeliverySpecController::class, 'update'])->name('tasks.delivery-spec'); // E-18
            Route::put('tasks/{task}/preview', [TaskPreviewController::class, 'update'])->middleware('throttle:20,1')->name('tasks.preview'); // E-26
            Route::delete('tasks/{task}/citations/{citation}', [CitationController::class, 'destroy'])->name('tasks.citations.destroy');
            Route::delete('tasks/{task}/mute', [TaskMuteController::class, 'destroy'])->name('tasks.unmute');
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
        Route::put('checklist-defaults', [WorkflowTemplateController::class, 'checklistDefaults'])->name('checklist-defaults.update'); // E-25
    });
});
