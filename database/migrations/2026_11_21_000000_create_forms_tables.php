<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-53 / E-54 / E-42: forms with published versions (old submissions keep the version
 * they were made with), an output mapping, a public intake link, and submissions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 1000)->nullable();
            $table->json('draft');                         // schema being edited
            $table->json('output');                        // E-54: what a submission does
            $table->unsignedBigInteger('current_version_id')->nullable();
            $table->string('public_token', 64)->nullable()->unique(); // E-42: intake link
            $table->boolean('archived')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('form_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('schema');
            $table->json('output');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->unique(['form_id', 'version']);
        });

        Schema::create('form_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20)->unique();      // shown to the person who submitted
            $table->string('idempotency_key', 64)->nullable()->unique(); // a retried submit returns the same submission
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('submitter_name', 120)->nullable(); // public intake
            $table->json('answers');
            $table->json('files')->nullable();              // attachment ids per file field
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['form_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submissions');
        Schema::dropIfExists('form_versions');
        Schema::dropIfExists('forms');
    }
};
