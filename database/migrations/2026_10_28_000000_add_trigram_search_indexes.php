<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * E-116: search uses lower(column) LIKE '%text%'. On PostgreSQL a trigram GIN index on
 * the same expression lets that query use an index instead of reading every row.
 * pg_trgm is a trusted extension (PG13+), so the app's database owner can create it.
 * Other drivers (SQLite in tests) keep plain LIKE and skip this migration.
 */
return new class extends Migration
{
    /** @var array<string, string> index name => table(expression) */
    private array $indexes = [
        'tasks_title_trgm' => 'tasks (lower(title) gin_trgm_ops)',
        'projects_name_trgm' => 'projects (lower(name) gin_trgm_ops)',
        'comments_body_trgm' => 'comments (lower(body) gin_trgm_ops)',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        foreach ($this->indexes as $name => $definition) {
            DB::statement("CREATE INDEX IF NOT EXISTS {$name} ON {$definition}");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_keys($this->indexes) as $name) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
