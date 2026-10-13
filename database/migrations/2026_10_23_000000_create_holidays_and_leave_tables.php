<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Findings batch 4 (B-14): Philippine holiday calendar and leave requests.
 * Both reduce a person's capacity (B-05 workload, B-16 people report, timesheet target).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('holidays', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->string('name', 120);
            // regular | special (non-working) | special_working (a normal working day)
            $table->string('kind', 20)->default('regular');
            $table->timestamps();
        });

        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('vacation'); // vacation | sick | emergency | other
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedSmallInteger('working_days');      // weekdays minus holidays, computed on save
            $table->string('reason', 500)->nullable();
            $table->string('status', 12)->default('pending');   // pending | approved | rejected | cancelled
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['starts_on', 'ends_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
        Schema::dropIfExists('holidays');
    }
};
