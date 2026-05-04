<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stream_file_settings', function (Blueprint $table) {
            $table->string('sports_league_source')->default('group')->after('tmdb_id_apply_to');
            $table->string('sports_static_league')->nullable()->after('sports_league_source');
            $table->string('sports_season_source')->default('title_year')->after('sports_static_league');
            $table->string('sports_episode_strategy')->default('sequential_per_season')->after('sports_season_source');
            $table->boolean('sports_repeat_league_in_filename')->default(true)->after('sports_episode_strategy');
            $table->boolean('sports_include_event_title')->default(true)->after('sports_repeat_league_in_filename');
        });
    }

    public function down(): void
    {
        Schema::table('stream_file_settings', function (Blueprint $table) {
            $table->dropColumn([
                'sports_league_source',
                'sports_static_league',
                'sports_season_source',
                'sports_episode_strategy',
                'sports_repeat_league_in_filename',
                'sports_include_event_title',
            ]);
        });
    }
};
