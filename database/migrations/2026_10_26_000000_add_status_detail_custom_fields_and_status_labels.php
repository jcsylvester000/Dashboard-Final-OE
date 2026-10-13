<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 9:
 *  R-22  projects.status_detail: what the client status page shows (simple | detailed).
 *  B-07  per-client custom task fields (definitions on the workspace, values on the task)
 *        and per-client names for the shared statuses (e.g. "In Review" -> "With client").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('status_detail', 10)->default('simple');
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->json('custom_fields')->nullable();
            $table->json('status_labels')->nullable();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->json('custom_values')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', fn (Blueprint $table) => $table->dropColumn('custom_values'));
        Schema::table('workspaces', fn (Blueprint $table) => $table->dropColumn(['custom_fields', 'status_labels']));
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn('status_detail'));
    }
};
