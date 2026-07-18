<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Generous pg_trgm inclusion threshold used to widen the EPG candidate
     * pool (see SimilaritySearchService::TRGM_CANDIDATE_THRESHOLD - keep
     * these two in sync).
     */
    private const TRGM_THRESHOLD = 0.35;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // pg_trgm backs the EPG candidate-recall `%` lookup in
        // SimilaritySearchService. Only relevant on Postgres; no-op elsewhere.
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // The `%` operator's selectivity estimate (and therefore whether the
        // planner picks the GIN index below at all) is computed by ANALYZE
        // using whatever pg_trgm.similarity_threshold is active at the time.
        // Setting it at the database level - not just per-session in the app
        // - keeps ANALYZE (including autovacuum's periodic runs) consistent
        // with the threshold the app actually queries with; otherwise the
        // planner silently falls back to a sequential scan.
        $database = DB::getDatabaseName();
        DB::statement("ALTER DATABASE \"{$database}\" SET pg_trgm.similarity_threshold = ".self::TRGM_THRESHOLD);
        DB::statement('SET pg_trgm.similarity_threshold = '.self::TRGM_THRESHOLD);

        // Expression indexes must match the LOWER(column) form used by the
        // `%` operator in SimilaritySearchService::trigramSearchCondition()
        // exactly, or Postgres falls back to a sequential scan.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_epg_channels_channel_id_trgm ON epg_channels USING gin (LOWER(channel_id) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_epg_channels_name_trgm ON epg_channels USING gin (LOWER(name) gin_trgm_ops)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_epg_channels_display_name_trgm ON epg_channels USING gin (LOWER(display_name) gin_trgm_ops)');

        // Refresh statistics for existing data now that the threshold is set,
        // so the index is usable immediately rather than after the next
        // autovacuum cycle.
        DB::statement('ANALYZE epg_channels');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS idx_epg_channels_channel_id_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_epg_channels_name_trgm');
        DB::statement('DROP INDEX IF EXISTS idx_epg_channels_display_name_trgm');

        $database = DB::getDatabaseName();
        DB::statement("ALTER DATABASE \"{$database}\" RESET pg_trgm.similarity_threshold");
    }
};
