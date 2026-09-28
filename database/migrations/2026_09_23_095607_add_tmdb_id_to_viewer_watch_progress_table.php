<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * viewer_watch_progress is written continuously by active playback sessions
     * (WatchProgressController::update()). On Postgres a plain CREATE INDEX
     * takes a SHARE lock for the build; if this table is huge in a given
     * deployment, pre-build the equivalent index with CREATE INDEX CONCURRENTLY
     * before deploying so this migration is a no-op.
     */
    public function up(): void
    {
        Schema::table('viewer_watch_progress', function (Blueprint $table) {
            $table->unsignedBigInteger('tmdb_id')->nullable()->after('stream_id');
            $table->index(['playlist_viewer_id', 'content_type', 'tmdb_id'], 'viewer_watch_progress_relink_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('viewer_watch_progress', function (Blueprint $table) {
            $table->dropIndex('viewer_watch_progress_relink_idx');
            $table->dropColumn('tmdb_id');
        });
    }
};
