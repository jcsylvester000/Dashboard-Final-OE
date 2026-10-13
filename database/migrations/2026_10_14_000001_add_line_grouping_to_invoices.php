<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How hour lines are grouped on an invoice: by department (default), project
 * (one line per campaign) or person (per team member). UX simulation 2026-10-06.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('line_grouping', 20)->default('department')->after('tax_rate_bp');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('line_grouping');
        });
    }
};
