<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-24 (D-04): GitHub repositories per client, webhook deliveries (idempotent) and
 * code links on tasks. E-26: preview URL with reachability. E-41 (D-05): optional chat channels.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('code_repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('full_name', 140)->unique();   // owner/repo, lower-case
            $table->string('on_open_status', 30)->nullable();  // task status slug when a PR opens
            $table->string('on_merge_status', 30)->nullable(); // ... when it merges
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('github_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('delivery_id', 64)->unique(); // X-GitHub-Delivery: replays are ignored
            $table->string('event', 40);
            $table->string('repository', 140)->nullable();
            $table->string('status', 20)->default('queued'); // queued | done | ignored | failed
            $table->string('result', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });

        Schema::create('task_code_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('repository', 140);
            $table->string('kind', 10);                 // pr | branch | commit
            $table->string('ref', 255);                 // #12 | branch name | short sha
            $table->unsignedInteger('number')->nullable();
            $table->string('head_sha', 64)->nullable();
            $table->string('title', 300)->nullable();
            $table->string('url', 2048);
            $table->string('state', 20)->nullable();    // open | draft | merged | closed
            $table->string('checks', 20)->nullable();   // success | failure | pending
            $table->json('reviewers')->nullable();
            $table->string('author', 100)->nullable();
            $table->timestamps();
            $table->unique(['task_id', 'repository', 'kind', 'ref']);
            $table->index('head_sha');
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->string('preview_url', 2048)->nullable();
            $table->string('preview_status', 10)->nullable(); // up | down
            $table->unsignedSmallInteger('preview_http')->nullable();
            $table->timestamp('preview_checked_at')->nullable();
            $table->timestamp('preview_alerted_at')->nullable();
        });

        Schema::create('chat_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 10);            // slack | teams
            $table->string('name', 60);
            $table->text('webhook_url');               // encrypted (it is a secret)
            $table->json('events');
            $table->boolean('enabled')->default(false); // off until a test message works
            $table->timestamp('last_ok_at')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_channels');
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['preview_url', 'preview_status', 'preview_http', 'preview_checked_at', 'preview_alerted_at']);
        });
        Schema::dropIfExists('task_code_links');
        Schema::dropIfExists('github_deliveries');
        Schema::dropIfExists('code_repositories');
    }
};
