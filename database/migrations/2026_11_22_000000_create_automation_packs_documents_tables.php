<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-37 automation rules + run log, E-51 scheduled report packs, E-52 document templates
 * (versioned), E-55 usage counts for the template gallery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('trigger', 30);              // status_changed | label_added | file_uploaded | due_soon
            $table->string('trigger_value', 60)->nullable(); // status slug or label id
            $table->json('conditions');                 // department_id, type, project_id
            $table->json('actions');                    // [{type, value}]
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('runs')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['workspace_id', 'trigger', 'enabled']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome', 10);              // done | skipped | failed | loop
            $table->string('detail', 500)->nullable();
            $table->timestamp('created_at');
            $table->index(['automation_rule_id', 'created_at']);
        });

        Schema::create('report_packs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('sections');
            $table->string('frequency', 10);            // weekly | monthly
            $table->unsignedTinyInteger('day');         // weekday 1-7 or day of month 1-28
            $table->json('recipient_ids');
            $table->boolean('enabled')->default(true);
            $table->date('next_run_on');
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('runs')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('document_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('kind', 30)->default('document'); // proposal | sow | brief | report | document
            $table->longText('body');                   // Markdown with {{merge.fields}}
            $table->unsignedInteger('version')->default(1);
            $table->boolean('archived')->default(false);
            $table->unsignedInteger('uses')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('document_template_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_template_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->longText('body');
            $table->foreignId('saved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->unique(['document_template_id', 'version']);
        });

        Schema::table('workflow_templates', function (Blueprint $table) {
            $table->unsignedInteger('uses')->default(0); // E-55
        });
    }

    public function down(): void
    {
        Schema::table('workflow_templates', fn (Blueprint $table) => $table->dropColumn('uses'));
        Schema::dropIfExists('document_template_versions');
        Schema::dropIfExists('document_templates');
        Schema::dropIfExists('report_packs');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');
    }
};
