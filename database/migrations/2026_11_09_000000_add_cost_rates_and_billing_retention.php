<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement batch E10b:
 *  - E-77 finance-only internal cost per person (PHP per hour, effective-dated), kept apart from bill rates
 *  - E-86 names on billing records are kept as written (snapshots), so erasing a person's
 *    personal data never changes an invoice's audit trail
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('cost_minor');                 // PHP per hour, minor units
            $table->date('effective_from');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'effective_from']);
        });

        Schema::table('invoice_history', function (Blueprint $table) {
            $table->string('user_name', 255)->nullable();
        });
        Schema::table('payments', function (Blueprint $table) {
            $table->string('recorded_by_name', 255)->nullable();
        });
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('finalized_by_name', 255)->nullable();
        });

        // Snapshot the names already on billing records.
        foreach ([['invoice_history', 'user_id', 'user_name'], ['payments', 'recorded_by', 'recorded_by_name'], ['invoices', 'finalized_by', 'finalized_by_name']] as [$table, $fk, $col]) {
            $ids = DB::table($table)->whereNotNull($fk)->distinct()->pluck($fk);
            foreach (DB::table('users')->whereIn('id', $ids)->get(['id', 'name']) as $u) {
                DB::table($table)->where($fk, $u->id)->whereNull($col)->update([$col => $u->name]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('finalized_by_name'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('recorded_by_name'));
        Schema::table('invoice_history', fn (Blueprint $table) => $table->dropColumn('user_name'));
        Schema::dropIfExists('cost_rates');
    }
};
