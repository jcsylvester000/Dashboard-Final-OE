<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement E-129: pre-computed time per day, person, client, project, task and department
 * (finished, not deleted entries). Reports read these instead of scanning every entry.
 * Kept current when entries change; a nightly check repairs any drift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_daily', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('task_id')->nullable();
            $table->unsignedBigInteger('department_id')->nullable();
            $table->unsignedInteger('minutes');
            $table->unsignedInteger('billable_minutes');
            $table->unsignedInteger('approved_minutes');
            $table->unsignedInteger('entries');

            $table->index(['entry_date', 'workspace_id']);
            $table->index(['user_id', 'entry_date']);
        });

        // Fill it from the existing time (runs once).
        Artisan::call('reports:aggregate', ['--all' => true]);
    }

    public function down(): void
    {
        Schema::dropIfExists('time_daily');
    }
};
