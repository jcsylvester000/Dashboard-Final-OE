<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprints and story points (brutal scenario J1): time-boxed iterations per client,
 * a backlog to plan from, burndown while running, a report and carry-over on close.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 80);
            $table->string('goal', 500)->nullable();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 10)->default('planned'); // planned | active | closed
            $table->timestamp('started_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            // Sprint report, frozen at start / close.
            $table->unsignedInteger('committed_points')->nullable();
            $table->unsignedInteger('completed_points')->nullable();
            $table->unsignedInteger('carried_over')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('sprint_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('story_points')->nullable();
            $table->index(['sprint_id', 'status_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['sprint_id', 'status_id']);
            $table->dropConstrainedForeignId('sprint_id');
            $table->dropColumn('story_points');
        });

        Schema::dropIfExists('sprints');
    }
};
