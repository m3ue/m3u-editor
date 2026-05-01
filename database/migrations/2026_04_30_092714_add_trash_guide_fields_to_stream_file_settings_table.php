<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stream_file_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('stream_file_settings', 'movie_format')) {
                $table->string('movie_format')->nullable()->default(null)->after('generate_nfo');
            }

            if (! Schema::hasColumn('stream_file_settings', 'episode_format')) {
                $table->string('episode_format')->nullable()->default(null)->after('movie_format');
            }

            if (! Schema::hasColumn('stream_file_settings', 'version_detection_pattern')) {
                $table->string('version_detection_pattern')->nullable()->after('episode_format');
            }

            if (! Schema::hasColumn('stream_file_settings', 'group_versions')) {
                $table->boolean('group_versions')->default(true)->after('version_detection_pattern');
            }

            if (! Schema::hasColumn('stream_file_settings', 'use_stream_stats')) {
                $table->boolean('use_stream_stats')->default(true)->after('group_versions');
            }

            if (! Schema::hasColumn('stream_file_settings', 'trash_guide_naming_enabled')) {
                $table->boolean('trash_guide_naming_enabled')->default(false)->after('use_stream_stats');
            }

            if (! Schema::hasColumn('stream_file_settings', 'trash_movie_components')) {
                $table->json('trash_movie_components')->nullable()->after('trash_guide_naming_enabled');
            }

            if (! Schema::hasColumn('stream_file_settings', 'trash_episode_components')) {
                $table->json('trash_episode_components')->nullable()->after('trash_movie_components');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stream_file_settings', function (Blueprint $table) {
            $table->dropColumn([
                'movie_format',
                'episode_format',
                'version_detection_pattern',
                'group_versions',
                'use_stream_stats',
                'trash_guide_naming_enabled',
                'trash_movie_components',
                'trash_episode_components',
            ]);
        });
    }
};
