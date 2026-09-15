<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add real-time download progress tracking columns to cached_content_files.
     *
     * Columns enable the Filament progress UI (Phase 5 of Dynamic Group Cache)
     * to surface byte-level download progress without polling external sources.
     * All three are nullable: bytes_expected is null when the upstream does not
     * advertise Content-Length (chunked transfer / no HEAD preflight).
     *
     * Schema notes:
     * - ADD COLUMN with no default is metadata-only in Postgres 11+, so this does
     *   not rewrite the table or take a heavy lock on the workers that write
     *   to cached_content_files from the dynamic-group-cache queue.
     * - No index added: queries are always by primary key (id) for progress
     *   updates and via content_fingerprint for dedup; no wide scans expected.
     */
    public function up(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table) {
            $table->bigInteger('bytes_downloaded')->nullable()->after('file_size_bytes');
            $table->bigInteger('bytes_expected')->nullable()->after('bytes_downloaded');
            $table->timestamp('last_progress_at')->nullable()->after('bytes_expected');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cached_content_files', function (Blueprint $table) {
            $table->dropColumn(['bytes_downloaded', 'bytes_expected', 'last_progress_at']);
        });
    }
};
