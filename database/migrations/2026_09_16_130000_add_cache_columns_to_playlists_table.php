<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-playlist cached content options.
     *
     * - `share_cache_across_playlists`: let this playlist's cached files be
     *   served to the same user's other playlists.
     * - `cache_retention_mode`: overrides the global retention mode; null
     *   falls back to `general.cache_retention_mode`.
     *
     * Both are metadata-only column adds on Postgres 11+ (constant default /
     * nullable), so they don't rewrite or lock the playlists table.
     */
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->boolean('share_cache_across_playlists')->default(false)->after('enable_proxy');
            $table->string('cache_retention_mode')->nullable()->after('share_cache_across_playlists');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table): void {
            $table->dropColumn(['share_cache_across_playlists', 'cache_retention_mode']);
        });
    }
};
