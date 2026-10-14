<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch E5:
 *  E-07 named reviewers per task with their decision, and the rule (all / any).
 *  E-33 when review was requested + each project's review time in working days.
 *  E-36 checklists per task (required items block Done) and per-type templates per client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_reviewers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('decision', 10)->nullable(); // approved | changes
            $table->string('note', 2000)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'user_id']);
            $table->index(['user_id', 'decision']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('review_rule', 3)->default('all'); // all | any
            $table->timestamp('review_requested_at')->nullable();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedTinyInteger('review_sla_days')->default(1);
        });

        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('label', 200);
            $table->boolean('required')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['task_id', 'position']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->json('checklist_templates')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn('checklist_templates'));
        Schema::dropIfExists('task_checklist_items');
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('review_sla_days'));
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn(['review_rule', 'review_requested_at']));
        Schema::dropIfExists('task_reviewers');
    }
};
