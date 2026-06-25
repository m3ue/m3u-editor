<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE "channels" ALTER COLUMN "stream_stats" TYPE jsonb USING "stream_stats"::jsonb'
        );
    }

    public function down(): void
    {
        if (config('database.default') !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE "channels" ALTER COLUMN "stream_stats" TYPE json USING "stream_stats"::json'
        );
    }
};
