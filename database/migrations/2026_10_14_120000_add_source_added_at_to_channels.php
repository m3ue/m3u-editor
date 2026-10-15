<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the provider (Xtream `added`) or media server (Emby/Jellyfin
     * `DateCreated`, Plex `addedAt`) says a VOD item was added, so clients can
     * sort by "Recently Added" instead of our own import time (created_at).
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dateTime('source_added_at')->nullable()->after('last_metadata_fetch');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('source_added_at');
        });
    }
};
