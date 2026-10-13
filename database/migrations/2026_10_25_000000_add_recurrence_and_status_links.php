<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Findings batch 6.
 * - B-04: recurring tasks (weekly / every 2 weeks / monthly), next copy made when one is finished.
 * - B-06: read-only client status page per project at /p/{token} (no login), optional expiry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->string('recurrence', 10)->nullable();   // weekly | biweekly | monthly
            $table->date('recurrence_until')->nullable();
            $table->foreignId('recurred_from_task_id')->nullable()->constrained('tasks')->nullOnDelete();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('status_token', 40)->nullable()->unique();
            $table->timestamp('status_token_expires_at')->nullable();
            $table->unsignedInteger('status_views')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropUnique(['status_token']);
            $table->dropColumn(['status_token', 'status_token_expires_at', 'status_views']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurred_from_task_id');
            $table->dropColumn(['recurrence', 'recurrence_until']);
        });
    }
};
