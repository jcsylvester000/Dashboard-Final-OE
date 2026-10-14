<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement batch E6b (finance workflow):
 *  - E-69 statement of account link per client
 *  - E-70 invoice link for clients (expiring, revocable, views tracked)
 *  - E-72 overdue follow-up stages, contact notes and promise-to-pay dates
 *  - E-73 periods opened and retainer drafts built by a scheduled job
 *  - E-75 expense category, markup, tax flag, receipt and approval
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('share_token', 40)->nullable()->unique();
            $table->timestamp('share_expires_at')->nullable();
            $table->timestamp('share_first_viewed_at')->nullable();
            $table->unsignedInteger('share_views')->default(0);
            $table->unsignedTinyInteger('collection_stage')->default(0); // 0 none, 1 = 7 days, 2 = 14, 3 = 30 (lead told)
            $table->date('promise_to_pay_on')->nullable();
            $table->timestamp('last_contact_at')->nullable();
        });

        Schema::create('invoice_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note', 1000);
            $table->date('promise_to_pay_on')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('billing_profiles', function (Blueprint $table) {
            $table->string('statement_token', 40)->nullable()->unique();
            $table->timestamp('statement_token_expires_at')->nullable();
        });

        Schema::table('billing_periods', function (Blueprint $table) {
            $table->timestamp('auto_drafted_at')->nullable(); // E-73: built once by the job
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->string('category', 30)->nullable();
            $table->unsignedInteger('markup_bp')->default(0);
            $table->boolean('taxable')->default(true);
            $table->string('receipt_path')->nullable();
            $table->string('receipt_name')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // Expenses recorded before approvals existed were already accepted by Finance.
        DB::table('expenses')->whereNull('approved_at')->update(['approved_at' => DB::raw('created_at')]);
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['category', 'markup_bp', 'taxable', 'receipt_path', 'receipt_name', 'approved_at']);
        });
        Schema::table('billing_periods', fn (Blueprint $table) => $table->dropColumn('auto_drafted_at'));
        Schema::table('billing_profiles', function (Blueprint $table) {
            $table->dropUnique(['statement_token']);
            $table->dropColumn(['statement_token', 'statement_token_expires_at']);
        });
        Schema::dropIfExists('invoice_contacts');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropUnique(['share_token']);
            $table->dropColumn(['share_token', 'share_expires_at', 'share_first_viewed_at', 'share_views', 'collection_stage', 'promise_to_pay_on', 'last_contact_at']);
        });
    }
};
