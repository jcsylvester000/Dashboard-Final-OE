<?php

use App\Models\Workspace;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Jira-style task keys (brutal scenario J2): each client gets a short key (BRW)
 * and its tasks a running number, so "BRW-24" means the same thing in chat,
 * commits and search. Tasks also get a type (task / story / bug / chore).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspaces', function (Blueprint $table) {
            $table->string('key', 6)->nullable()->unique();
            $table->unsignedInteger('next_task_number')->default(0);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->unsignedInteger('number')->nullable();
            $table->string('type', 10)->default('task');
            $table->unique(['workspace_id', 'number']);
        });

        // Backfill: key from the client name, numbers in creation order (soft-deleted included, never reused).
        $taken = [];
        foreach (DB::table('workspaces')->orderBy('id')->get(['id', 'name']) as $w) {
            $key = Workspace::suggestKey((string) $w->name, $taken);
            $taken[] = $key;

            $n = 0;
            foreach (DB::table('tasks')->where('workspace_id', $w->id)->orderBy('id')->pluck('id') as $taskId) {
                DB::table('tasks')->where('id', $taskId)->update(['number' => ++$n]);
            }

            DB::table('workspaces')->where('id', $w->id)->update(['key' => $key, 'next_task_number' => $n]);
        }
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropUnique(['workspace_id', 'number']);
            $table->dropColumn(['number', 'type']);
        });

        Schema::table('workspaces', function (Blueprint $table) {
            $table->dropUnique(['key']);
            $table->dropColumn(['key', 'next_task_number']);
        });
    }
};
