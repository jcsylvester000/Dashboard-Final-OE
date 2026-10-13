<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Findings batch 3 (S1-17, B-02, S2-09): tasks get a start date so the calendar and
 * timeline can draw start-to-due bars. Milestones are a task type (no new table).
 * Existing tasks start on the day they were created (never after their due date).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->date('start_on')->nullable()->after('due_on');
            $table->index(['workspace_id', 'start_on']);
        });

        foreach (DB::table('tasks')->whereNotNull('due_on')->get(['id', 'created_at', 'due_on']) as $t) {
            $created = substr((string) $t->created_at, 0, 10);
            $start = $created !== '' && $created <= (string) $t->due_on ? $created : (string) $t->due_on;
            DB::table('tasks')->where('id', $t->id)->update(['start_on' => $start]);
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'start_on']);
            $table->dropColumn('start_on');
        });
    }
};
