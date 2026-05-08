<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a DB-level dedup token for `Playlist::dispatchSyncCompletedOnce()`.
     *
     * The token is set to a UUID when a new sync window opens
     * (`resetSyncCompletedGuard`) and atomically cleared via a single-row
     * UPDATE WHERE NOT NULL when the SyncCompleted event is about to fire.
     * Only the worker that reduces affected-rows to 1 fires the event; all
     * others get 0 and return early. This is safe across queue workers on any
     * database backend without requiring Redis atomicity.
     */
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->string('sync_event_token', 36)->nullable()->after('synced');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('sync_event_token');
        });
    }
};
