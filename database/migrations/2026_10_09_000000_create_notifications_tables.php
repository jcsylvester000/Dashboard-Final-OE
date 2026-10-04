<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 notifications: in-app only (no email). Laravel's notifications table plus
 * inbox columns (kind, workspace, quiet, snooze, done), per-user preferences,
 * and task_alerts so due-soon / overdue / escalation alerts fire once per due date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->string('kind', 32)->index();
            // Lets the inbox hide alerts from workspaces the user can no longer open.
            $table->foreignId('workspace_id')->nullable()->constrained()->nullOnDelete();
            // Digest-mode alerts: listed in the inbox and digest, not pushed or counted on the bell.
            $table->boolean('quiet')->default(false);
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('snoozed_until')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_id', 'done_at', 'read_at']);
        });

        Schema::create('task_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20); // due_soon | overdue | escalation
            $table->date('due_on');
            $table->timestamp('created_at')->nullable();

            $table->unique(['task_id', 'kind', 'due_on']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('notification_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
        Schema::dropIfExists('task_alerts');
        Schema::dropIfExists('notifications');
    }
};
