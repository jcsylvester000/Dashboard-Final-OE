<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement batch E10a (E-74): unused retainer hours can roll over (cap, expiry), and
 * prepaid hour blocks. Both are hour balances that invoices draw down; the balance is
 * computed from the invoice lines that use it, so it can never go below zero.
 * Deposits use the existing client credit ledger (E-68).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_profiles', function (Blueprint $table) {
            $table->boolean('rollover_enabled')->default(false);
            $table->unsignedInteger('rollover_cap_minutes')->nullable();   // null = no cap
            $table->unsignedSmallInteger('rollover_months')->default(1);   // months the hours stay usable
        });

        Schema::create('prepaid_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);                                     // rollover | block
            $table->unsignedInteger('minutes');
            $table->bigInteger('amount_minor')->default(0);                 // block price (0 for rollover)
            $table->char('currency', 3)->default('PHP');
            $table->date('starts_on');
            $table->date('expires_on')->nullable();
            $table->foreignId('source_invoice_id')->nullable()->constrained('invoices')->nullOnDelete(); // rollover: the invoice it came from
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();        // block: the invoice that sells it
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'starts_on']);
        });

        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('prepaid_hours_id')->nullable()->constrained('prepaid_hours')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prepaid_hours_id');
        });
        Schema::dropIfExists('prepaid_hours');
        Schema::table('billing_profiles', function (Blueprint $table) {
            $table->dropColumn(['rollover_enabled', 'rollover_cap_minutes', 'rollover_months']);
        });
    }
};
