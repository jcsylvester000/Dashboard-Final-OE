<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement batch E13a:
 *  - E-34 review rules: who reviews by default, per client, optionally per project,
 *    deliverable type and task type; "all", "any" or round-robin
 *  - E-38 when a named reviewer last opened the task (approve from the alert needs a recent look)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('deliverable_type', 40)->nullable();
            $table->string('task_type', 20)->nullable();
            $table->json('user_ids');
            $table->string('mode', 12)->default('all');      // all | any | round_robin
            $table->unsignedInteger('next_index')->default(0); // round-robin pointer
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'project_id']);
        });

        Schema::table('task_reviewers', function (Blueprint $table) {
            $table->timestamp('seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_reviewers', fn (Blueprint $table) => $table->dropColumn('seen_at'));
        Schema::dropIfExists('review_rules');
    }
};
