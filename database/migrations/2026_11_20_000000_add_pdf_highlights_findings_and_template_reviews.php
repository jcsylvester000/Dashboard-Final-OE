<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-30: text highlights on PDF pages. E-31: findings library. E-40: workflow template
 * steps with reviewers, SLA and checklist; working-day due dates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annotations', function (Blueprint $table) {
            $table->unsignedSmallInteger('page')->nullable()->after('end_ms'); // E-30: 1-based PDF page
            $table->text('quote')->nullable()->after('page');                  // highlighted text
            $table->json('rects')->nullable()->after('quote');                 // [[x, y, w, h], ...] as 0..1 of the page
        });

        Schema::create('findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->json('tags');
            $table->string('confidence', 10);                  // low | medium | high
            $table->string('source_type', 20);                 // comment | annotation | manual
            $table->unsignedBigInteger('source_id')->nullable();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('attachment_id')->nullable()->constrained()->nullOnDelete();
            $table->text('quote')->nullable();
            $table->unsignedSmallInteger('page')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('finding_task', function (Blueprint $table) {
            $table->foreignId('finding_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->primary(['finding_id', 'task_id']);
        });

        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->boolean('working_days')->default(false); // E-40: offsets count weekdays, skipping holidays
        });

        Schema::table('workflow_template_steps', function (Blueprint $table) {
            $table->json('review_user_ids')->nullable();
            $table->boolean('review_leads')->default(false);
            $table->string('review_mode', 3)->nullable();         // all | any
            $table->unsignedTinyInteger('review_sla_days')->nullable();
            $table->json('checklist')->nullable();                // lines; "? " prefix = optional
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedTinyInteger('review_sla_days')->nullable(); // E-40: overrides the project's
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('review_sla_days'));
        Schema::table('workflow_template_steps', fn (Blueprint $table) => $table->dropColumn(['review_user_ids', 'review_leads', 'review_mode', 'review_sla_days', 'checklist']));
        Schema::table('workflow_templates', fn (Blueprint $table) => $table->dropColumn('working_days'));
        Schema::dropIfExists('finding_task');
        Schema::dropIfExists('findings');
        Schema::table('annotations', fn (Blueprint $table) => $table->dropColumn(['page', 'quote', 'rects']));
    }
};
