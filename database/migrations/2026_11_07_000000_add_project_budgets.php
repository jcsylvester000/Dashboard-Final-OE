<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement batch E9b: project budgets with burn alerts (E-76) in the client's currency (E-110).
 * Hours and/or an amount; each of 75 / 90 / 100 % alerts once (the highest step sent is kept).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedInteger('budget_minutes')->nullable();
            $table->bigInteger('budget_minor')->nullable();
            $table->char('budget_currency', 3)->nullable();
            $table->unsignedSmallInteger('budget_hours_alerted_pct')->default(0);
            $table->unsignedSmallInteger('budget_amount_alerted_pct')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['budget_minutes', 'budget_minor', 'budget_currency', 'budget_hours_alerted_pct', 'budget_amount_alerted_pct']);
        });
    }
};
