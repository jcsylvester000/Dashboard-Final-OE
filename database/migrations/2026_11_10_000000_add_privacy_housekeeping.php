<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement batch E11b (E-99, Data Privacy Act housekeeping): staff acknowledgements of
 * the privacy notice (one row per person per notice version, with the date) and an
 * admin-only personal data incident log. Notice text and DPO contact live in app_settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('privacy_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->timestamp('acknowledged_at');

            $table->unique(['user_id', 'version']);
        });

        Schema::create('privacy_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->dateTime('discovered_at');
            $table->dateTime('occurred_at')->nullable();
            $table->text('description');
            $table->string('data_involved', 500)->nullable();   // kinds of data, never the data itself
            $table->unsignedInteger('people_affected')->nullable();
            $table->string('severity', 10);                      // low | medium | high
            $table->string('status', 12)->default('open');       // open | contained | closed
            $table->dateTime('npc_notified_at')->nullable();
            $table->dateTime('people_notified_at')->nullable();
            $table->text('actions')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('privacy_incidents');
        Schema::dropIfExists('privacy_acknowledgements');
    }
};
