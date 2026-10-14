<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement batch E6a (finance workflow):
 *  - E-63 manual lines and discounts on drafts (kept when the draft is rebuilt)
 *  - E-66 review before finalize (maker-checker)
 *  - E-67 issue date and service-period label
 *  - E-68 / E-105 payment reversal, client credit, fees, withholding, received amount
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->boolean('manual')->default(false);               // added by Finance; survives a rebuild
            $table->boolean('taxable')->default(true);
            $table->unsignedInteger('discount_bp')->nullable();       // percentage discounts recompute from this
            $table->foreignId('applies_to_item_id')->nullable()->constrained('invoice_items')->nullOnDelete();
            $table->string('reason', 255)->nullable();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('service_label', 120)->nullable();         // e.g. "October 2026 retainer"
            $table->timestamp('review_requested_at')->nullable();
            $table->foreignId('review_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('review_note', 500)->nullable();           // last send-back note
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->bigInteger('fee_minor')->default(0);              // bank / gateway charges (invoice currency)
            $table->bigInteger('withholding_minor')->default(0);      // tax withheld by the client (BIR 2307)
            $table->bigInteger('received_minor')->nullable();         // what reached the bank
            $table->char('received_currency', 3)->nullable();
            $table->decimal('fx_rate', 18, 8)->nullable();            // received per 1 invoice-currency unit
            $table->bigInteger('credit_minor')->default(0);           // overpayment moved to client credit
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reversal_reason', 500)->nullable();
        });

        // Client credit: overpayments in, credit applied to invoices out. Append-only ledger.
        Schema::create('client_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->bigInteger('amount_minor');                       // + credit added, - credit used
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['workspace_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_credits');
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reversed_by');
            $table->dropColumn(['fee_minor', 'withholding_minor', 'received_minor', 'received_currency', 'fx_rate', 'credit_minor', 'reversed_at', 'reversal_reason']);
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_requested_by');
            $table->dropColumn(['service_label', 'review_requested_at', 'review_note']);
        });
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('applies_to_item_id');
            $table->dropColumn(['manual', 'taxable', 'discount_bp', 'reason']);
        });
    }
};
