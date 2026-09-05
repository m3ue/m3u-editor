<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Splits the single auto-reclassify toggle into two independent ones — one
     * for VOD groups, one for Series categories — so playlists can run the
     * auto-reclassify on VOD while leaving Series categorization alone if genre
     * routing doesn't fit series content.
     */
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->renameColumn('reclassify_groups_to_tmdb_genres', 'reclassify_vod_groups_to_tmdb_genres');
            $table->boolean('reclassify_series_categories_to_tmdb_genres')->default(false)->after('reclassify_vod_groups_to_tmdb_genres');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('reclassify_series_categories_to_tmdb_genres');
            $table->renameColumn('reclassify_vod_groups_to_tmdb_genres', 'reclassify_groups_to_tmdb_genres');
        });
    }
};
