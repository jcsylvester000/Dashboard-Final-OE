<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement batch E13b:
 *  - E-58 quiet mode: focus now / daily focus window / hold alerts outside working hours,
 *    and per-task snooze of alerts
 *  - E-59 guided first run: checklist state per person
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('quiet_settings')->nullable();
            $table->timestamp('focus_until')->nullable();
            $table->json('onboarding')->nullable();
        });

        Schema::create('task_mutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->timestamp('until');
            $table->timestamps();

            $table->unique(['user_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_mutes');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['quiet_settings', 'focus_until', 'onboarding']));
    }
};
