<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P6 billing, modelled on Kill Bill's patterns (not its server):
 *  - money is always integer minor units (centavos / cents), never float
 *  - finalized invoices are immutable; corrections are credit notes
 *  - every invoice line keeps links to what it bills (invoice_item_sources)
 *  - invoice_history is append-only
 * Clients only ever receive PDFs; nothing here sends email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('currency', 3)->default('PHP');
            $table->string('cycle', 20)->default('monthly');          // monthly | quarterly
            $table->unsignedBigInteger('retainer_minor')->default(0);
            $table->unsignedInteger('included_minutes')->default(0);  // hours covered by the retainer
            $table->string('overage_rule', 20)->default('bill');      // bill | absorb
            $table->unsignedSmallInteger('terms_days')->default(15);
            $table->unsignedInteger('tax_rate_bp')->default(0);       // basis points: 1200 = 12% VAT
            $table->string('invoice_series', 10)->default('INV');
            $table->json('bill_to')->nullable();                      // name, address, tax id, contact
            $table->text('payment_instructions')->nullable();
            $table->timestamps();
        });

        // Hourly rates. Most specific wins: workspace+person > workspace+department >
        // workspace default > person > department > agency default (see RateResolver).
        Schema::create('rate_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('rate_minor');                  // billed per hour
            $table->unsignedBigInteger('cost_minor')->nullable();      // internal cost per hour (profitability)
            $table->date('effective_from');
            $table->timestamps();

            $table->index(['workspace_id', 'department_id', 'user_id', 'effective_from'], 'rate_cards_lookup_index');
        });

        Schema::create('billing_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 20)->default('open');            // open | locked | invoiced
            $table->timestamp('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'starts_on']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_period_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20)->default('invoice');           // invoice | credit_note
            $table->foreignId('credited_invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->string('series', 10);
            $table->unsignedInteger('number')->nullable();             // assigned at finalize
            $table->string('status', 20)->default('draft');           // draft | finalized | partially_paid | paid | void
            $table->char('currency', 3);
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->bigInteger('subtotal_minor')->default(0);
            $table->unsignedInteger('tax_rate_bp')->default(0);
            $table->bigInteger('tax_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('paid_minor')->default(0);
            $table->bigInteger('credited_minor')->default(0);         // sum of finalized credit notes
            $table->json('bill_to')->nullable();                      // frozen copy at finalize
            $table->text('notes')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('overdue_alerted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['series', 'number']);
            $table->index(['workspace_id', 'status']);
            $table->index(['status', 'due_date']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20);                               // retainer | hours | fixed | expense | credit | adjustment
            $table->string('description');
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('minutes')->nullable();            // hours lines
            $table->decimal('quantity', 12, 2)->default(1);
            $table->bigInteger('unit_minor')->default(0);
            $table->bigInteger('amount_minor');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        // Traceability (Kill Bill tracking-ID pattern): every line -> what it bills.
        Schema::create('invoice_item_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_item_id')->constrained()->cascadeOnDelete();
            $table->morphs('source');                                 // time_entry | expense | project
            $table->unsignedInteger('minutes')->nullable();
            $table->bigInteger('amount_minor')->nullable();
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('invoice_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('note', 500)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_minor');
            $table->string('method', 20);                             // bank | gcash | maya | card | check | cash
            $table->string('reference', 100)->nullable();
            $table->date('received_on');
            $table->string('note', 500)->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description');
            $table->unsignedBigInteger('amount_minor');
            $table->date('incurred_on');
            $table->boolean('is_billable')->default(true);
            $table->foreignId('invoice_item_id')->nullable()->constrained()->nullOnDelete(); // set when billed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'incurred_on']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedBigInteger('fixed_fee_minor')->nullable();
            $table->timestamp('fixed_fee_billed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['fixed_fee_minor', 'fixed_fee_billed_at']);
        });
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_history');
        Schema::dropIfExists('invoice_item_sources');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('billing_periods');
        Schema::dropIfExists('rate_cards');
        Schema::dropIfExists('billing_profiles');
    }
};
