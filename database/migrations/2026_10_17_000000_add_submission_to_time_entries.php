<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weekly timesheet workflow: a person submits their week; an approver approves
 * entries or returns them with a reason; the person fixes and resubmits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('is_billable');
            $table->timestamp('returned_at')->nullable()->after('submitted_at');
            $table->foreignId('returned_by')->nullable()->after('returned_at')->constrained('users')->nullOnDelete();
            $table->string('return_reason', 500)->nullable()->after('returned_by');
        });
    }

    public function down(): void
    {
        Schema::table('time_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['submitted_at', 'returned_at', 'return_reason']);
        });
    }
};
