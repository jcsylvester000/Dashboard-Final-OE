<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Projects, campaigns, SEO engagements, research studies and product releases.
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 20)->default('project')->index();
            $table->string('status', 20)->default('planning')->index();
            $table->foreignId('lead_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('description')->nullable();
            $table->date('start_on')->nullable();
            $table->date('due_on')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('project_user', function (Blueprint $table) {
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->primary(['project_id', 'user_id']);
        });

        // Workspace-level colour labels, attachable to projects now and tasks in P3.
        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50);
            $table->string('color', 20)->default('slate');
            $table->timestamps();

            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('labelables', function (Blueprint $table) {
            $table->foreignId('label_id')->constrained()->cascadeOnDelete();
            $table->morphs('labelable');
            $table->primary(['label_id', 'labelable_type', 'labelable_id']);
        });

        // @mentions of team members inside any record's text.
        Schema::create('mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('mentioned_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('mentioned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->morphs('mentionable');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['mentioned_user_id', 'mentionable_type', 'mentionable_id']);
            $table->index(['mentioned_user_id', 'created_at']);
        });

        // Cross-references between records, possibly across workspaces. Backlinks = reverse lookup.
        Schema::create('record_links', function (Blueprint $table) {
            $table->id();
            $table->morphs('source');
            $table->morphs('target');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['source_type', 'source_id', 'target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_links');
        Schema::dropIfExists('mentions');
        Schema::dropIfExists('labelables');
        Schema::dropIfExists('labels');
        Schema::dropIfExists('project_user');
        Schema::dropIfExists('projects');
    }
};
