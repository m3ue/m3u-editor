<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the per-playlist "dynamic groups (TMDB)" repeater config column.
     *
     * Matches the column style of `auto_sync_to_custom_config`
     * (`jsonb`, nullable, placed after `channel_enable_rules` so the rule-config
     * repeaters stay grouped visually for future migrations).
     */
    public function up(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->jsonb('dynamic_groups_config')->nullable()->after('channel_enable_rules');
        });
    }

    public function down(): void
    {
        Schema::table('playlists', function (Blueprint $table) {
            $table->dropColumn('dynamic_groups_config');
        });
    }
};
