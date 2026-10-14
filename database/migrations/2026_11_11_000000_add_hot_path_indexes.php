<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enhancement E-117: indexes for the hot queries that had none. PostgreSQL does not index
 * foreign keys by itself, so every "lines of this invoice" style lookup was a full scan.
 *
 *  invoice_items(invoice_id)            invoice page, recalculate, PDF, markBilled
 *  invoice_items(prepaid_hours_id)      prepaid balances (E-74, drawn per block)
 *  invoice_item_sources(invoice_item_id) markBilled, profitability joins, work report
 *  invoice_history(invoice_id)          invoice history panel
 *  payments(invoice_id)                 invoice page, aging, cash received
 *  time_entries(project_id, entry_date) project page hours, budgets (E-76), profitability
 *  tasks(parent_id)                     subtasks on the task page, board "no subtasks" filter
 *  expenses(project_id)                 budgets amount burn, project invoices
 */
return new class extends Migration
{
    /** @var list<array{0: string, 1: list<string>}> */
    private array $indexes = [
        ['invoice_items', ['invoice_id']],
        ['invoice_items', ['prepaid_hours_id']],
        ['invoice_item_sources', ['invoice_item_id']],
        ['invoice_history', ['invoice_id']],
        ['payments', ['invoice_id']],
        ['time_entries', ['project_id', 'entry_date']],
        ['tasks', ['parent_id']],
        ['expenses', ['project_id']],
    ];

    public function up(): void
    {
        foreach ($this->indexes as [$table, $columns]) {
            if (! Schema::hasIndex($table, $columns)) {
                Schema::table($table, fn (Blueprint $t) => $t->index($columns, $this->name($table, $columns)));
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$table, $columns]) {
            if (Schema::hasIndex($table, $this->name($table, $columns))) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($this->name($table, $columns)));
            }
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function name(string $table, array $columns): string
    {
        return 'e117_'.$table.'_'.implode('_', $columns).'_index';
    }
};
