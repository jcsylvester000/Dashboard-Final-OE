<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement batch E14b:
 *  - E-29 sources (citations) on tasks: URL, page title, site, when it was read
 *  - E-27 release notes per sprint, drafted at close, with an optional share link
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_citations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('title', 300)->nullable();
            $table->string('site', 150)->nullable();
            $table->timestamp('accessed_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('task_id');
        });

        Schema::table('sprints', function (Blueprint $table) {
            $table->text('release_notes')->nullable();
            $table->string('release_notes_token', 40)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('sprints', fn (Blueprint $table) => $table->dropColumn(['release_notes', 'release_notes_token']));
        Schema::dropIfExists('task_citations');
    }
};
