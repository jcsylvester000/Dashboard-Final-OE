<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P5 reports: weekly per-workspace snapshots (kept in-app for leads) and the
 * extra indexes the report and dashboard queries use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('week_start');
            $table->json('data');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'week_start']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->index(['workspace_id', 'completed_at']);
            $table->index(['workspace_id', 'due_on']);
            $table->index(['assignee_id', 'due_on']);
            $table->index('handoff_from_task_id');
        });

        Schema::table('time_entries', function (Blueprint $table) {
            $table->index(['entry_date', 'department_id']);
        });

        Schema::table('activity_log', function (Blueprint $table) {
            $table->index(['subject_type', 'subject_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['subject_type', 'subject_id', 'id']);
        });
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropIndex(['entry_date', 'department_id']);
        });
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'completed_at']);
            $table->dropIndex(['workspace_id', 'due_on']);
            $table->dropIndex(['assignee_id', 'due_on']);
            $table->dropIndex(['handoff_from_task_id']);
        });
        Schema::dropIfExists('report_snapshots');
    }
};
