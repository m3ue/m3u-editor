<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a denormalized title column to cached_content_files so the Filament
     * activity widget can show "Wicked" instead of "movie: tmdb 860508" without
     * a TMDB API call on every page render.
     *
     * Populated lazily by DownloadCachedContentFile::resolveAndStoreTitle() right
     * after Step 5 creates/reclaims the row. Once set, the row never re-fetches
     * (TMDB titles are stable for our purposes). Nullable: rows created before
     * this migration or whose TMDB lookup failed will fall back to the legacy
     * "type: tmdb N" label in the widget.
     *
     * Schema notes:
     * - 500 chars covers series names + episode names + em-dash separators with
     *   headroom. TMDB's longest realistic title (~"A" titles, foreign-language
     *   transliterations) sits well under 300 chars.
     * - ADD COLUMN with no default is metadata-only in Postgres 11+, no table
     *   rewrite, no lock on the workers writing to cached_content_files from the
     *   dynamic-group-cache queue.
     */
    public function up(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table) {
            $table->string('title', 500)->nullable()->after('content_fingerprint');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table) {
            $table->dropColumn('title');
        });
    }
};
