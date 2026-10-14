<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement batch E9a (multi-currency):
 *  - E-103 exchange rates Finance enters by hand (PHP per 1 unit, per day)
 *  - E-104 the rate is locked on each invoice (issue date) and payment (payment date)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->char('currency', 3);
            $table->date('rate_on');
            $table->decimal('rate', 18, 6);                       // PHP for 1 unit of the currency
            $table->string('note', 255)->nullable();              // source, or why it was changed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['currency', 'rate_on']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('php_rate', 18, 6)->nullable();
            $table->date('php_rate_on')->nullable();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('php_rate', 18, 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('php_rate'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn(['php_rate', 'php_rate_on']));
        Schema::dropIfExists('exchange_rates');
    }
};
