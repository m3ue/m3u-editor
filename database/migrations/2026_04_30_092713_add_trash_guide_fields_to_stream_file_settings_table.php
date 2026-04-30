<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stream_file_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('stream_file_settings', 'movie_format')) {
                $table->string('movie_format')
                    ->default('{title} ({year}){edition}{quality}{audio}{video}{-group}')
                    ->after('generate_nfo');
            }

            if (! Schema::hasColumn('stream_file_settings', 'episode_format')) {
                $table->string('episode_format')
                    ->default('{title} - S{season}E{episode}{-title}{quality}{audio}{video}{-group}')
                    ->after('movie_format');
            }

            if (! Schema::hasColumn('stream_file_settings', 'version_detection_pattern')) {
                $table->string('version_detection_pattern')
                    ->nullable()
                    ->after('episode_format');
            }

            if (! Schema::hasColumn('stream_file_settings', 'group_versions')) {
                $table->boolean('group_versions')
                    ->default(true)
                    ->after('version_detection_pattern');
            }

            if (! Schema::hasColumn('stream_file_settings', 'use_stream_stats')) {
                $table->boolean('use_stream_stats')
                    ->default(true)
                    ->after('group_versions');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stream_file_settings', function (Blueprint $table) {
            $table->dropColumn([
                'movie_format',
                'episode_format',
                'version_detection_pattern',
                'group_versions',
                'use_stream_stats',
            ]);
        });
    }
};
