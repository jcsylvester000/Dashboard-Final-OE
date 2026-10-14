<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch E4:
 *  E-02 file versions: a new version joins its stack (version_root_id = first file),
 *       numbered 1..n, and only the newest is "latest".
 *  E-06 one review decision per version: approved / changes (with a note).
 *  E-21 structured bug reports on bug tasks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->foreignId('version_root_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('is_latest')->default(true);
            $table->string('review_status', 10)->nullable(); // approved | changes
            $table->string('review_note', 2000)->nullable();
            $table->index(['attachable_type', 'attachable_id', 'is_latest']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->json('bug_report')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('bug_report'));
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropIndex(['attachable_type', 'attachable_id', 'is_latest']);
            $table->dropConstrainedForeignId('version_root_id');
            $table->dropColumn(['version', 'is_latest', 'review_status', 'review_note']);
        });
    }
};
