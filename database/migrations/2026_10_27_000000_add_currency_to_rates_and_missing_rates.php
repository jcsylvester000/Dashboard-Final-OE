<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Batch E1:
 *  E-100 rate cards and rate templates carry a currency, so a USD/EUR client never
 *        falls back to peso rates. Existing client cards take their client's currency.
 *  E-64  a draft remembers the work it could not price (missing_rates) instead of failing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rate_cards', function (Blueprint $table) {
            $table->char('currency', 3)->default('PHP');
        });
        Schema::table('rate_templates', function (Blueprint $table) {
            $table->char('currency', 3)->default('PHP');
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->json('missing_rates')->nullable();
        });

        foreach (DB::table('billing_profiles')->where('currency', '!=', 'PHP')->get(['workspace_id', 'currency']) as $p) {
            DB::table('rate_cards')->where('workspace_id', $p->workspace_id)->update(['currency' => $p->currency]);
        }
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('missing_rates'));
        Schema::table('rate_templates', fn (Blueprint $table) => $table->dropColumn('currency'));
        Schema::table('rate_cards', fn (Blueprint $table) => $table->dropColumn('currency'));
    }
};
