<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement batch E7b (admin tools):
 *  - E-93 planned end of access for contractors (auto-deactivated; lead alerted a week before)
 *  - E-94 archived departments (hidden from pickers, kept in reports)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('access_ends_on')->nullable();
            $table->timestamp('access_end_alerted_at')->nullable();
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('departments', fn (Blueprint $table) => $table->dropColumn('archived_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['access_ends_on', 'access_end_alerted_at']));
    }
};
