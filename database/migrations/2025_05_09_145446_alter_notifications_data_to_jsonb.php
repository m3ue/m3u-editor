<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Only run migration if using pgsql. Check the connection's actual
        // driver rather than config('database.default') — the default
        // connection is frequently named something other than "pgsql" (e.g.
        // "pg_test" in testing/CI) despite being the same Postgres driver.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement(
            <<<'SQL'
ALTER TABLE notifications
  ALTER COLUMN data
    TYPE jsonb
    USING data::jsonb;
SQL
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Only run migration if using pgsql. Check the connection's actual
        // driver rather than config('database.default') — the default
        // connection is frequently named something other than "pgsql" (e.g.
        // "pg_test" in testing/CI) despite being the same Postgres driver.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        DB::statement(
            <<<'SQL'
ALTER TABLE notifications
  ALTER COLUMN data
    TYPE text
    USING data::text;
SQL
        );
    }
};
