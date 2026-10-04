<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Time logged against tasks. This is the source of truth for billing (P6).
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->date('entry_date');
            $table->timestamp('started_at')->nullable();   // set for timer entries
            $table->timestamp('ended_at')->nullable();     // null while a timer is running
            $table->unsignedInteger('minutes')->default(0);
            $table->string('note', 500)->nullable();
            $table->boolean('is_billable')->default(true);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('locked_at')->nullable();    // set when billed (P6)
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'entry_date']);
            $table->index(['workspace_id', 'entry_date']);
            $table->index(['task_id']);
            $table->index(['user_id', 'ended_at']);
        });

        // Department-specific detail on a task: Marketing (channel, campaign, deliverable)
        // and SEO (target URL, keyword, work type). JSON keeps the task table generic.
        Schema::table('tasks', function (Blueprint $table) {
            $table->json('work_details')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn('work_details');
        });
        Schema::dropIfExists('time_entries');
    }
};
