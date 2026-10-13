<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How two records are linked (Jira-style): relates to, duplicates, follow-up of.
 * "Blocks / waiting on" stays in task_dependencies because it drives the workflow.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('record_links', function (Blueprint $table) {
            $table->string('relation', 20)->default('relates')->after('target_id');
        });
    }

    public function down(): void
    {
        Schema::table('record_links', function (Blueprint $table) {
            $table->dropColumn('relation');
        });
    }
};
