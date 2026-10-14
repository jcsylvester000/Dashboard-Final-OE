<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * E-14..E-19: timecoded comments and drawings on video, comments carried to the
 * next cut, caption tracks, and delivery specs checked against each upload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annotations', function (Blueprint $table) {
            $table->unsignedInteger('time_ms')->nullable()->after('y');   // E-15: where on the timeline
            $table->unsignedInteger('end_ms')->nullable()->after('time_ms'); // range comments
            $table->foreignId('carried_from_id')->nullable()->after('end_ms')->constrained('annotations')->nullOnDelete(); // E-17
            $table->unsignedSmallInteger('carried_from_version')->nullable()->after('carried_from_id');
        });

        Schema::create('attachment_captions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attachment_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60);
            $table->string('language', 12)->nullable();
            $table->string('key');                      // WebVTT on the private local disk
            $table->unsignedInteger('cues');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('delivery_specs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('channel', 40)->nullable();     // matches the task's Channels (marketing)
            $table->string('deliverable', 40)->nullable(); // matches the task's Deliverable
            $table->string('aspect', 9)->nullable();       // "9:16"
            $table->unsignedSmallInteger('min_width')->nullable();
            $table->unsignedSmallInteger('min_height')->nullable();
            $table->unsignedInteger('max_duration_s')->nullable();
            $table->unsignedInteger('max_size_mb')->nullable();
            $table->boolean('captions_required')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('delivery_spec_id')->nullable()->constrained('delivery_specs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_spec_id');
        });
        Schema::dropIfExists('delivery_specs');
        Schema::dropIfExists('attachment_captions');
        Schema::table('annotations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('carried_from_id');
            $table->dropColumn(['time_ms', 'end_ms', 'carried_from_version']);
        });
    }
};
