<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds fields specific to local media integration:
     * - local_media_paths: Configured local paths to scan for media
     * - metadata_source: Where to fetch metadata ('tmdb', 'tvdb', 'filename_only')
     * - scan_recursive: Whether to scan subdirectories
     */
    public function up(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            // Local media paths configuration (array of path configurations)
            // Each entry: { path: string, type: 'movies'|'tvshows', name: string }
            $table->jsonb('local_media_paths')->nullable()->after('selected_library_ids');

            // Source for metadata enrichment
            $table->string('metadata_source')->default('tmdb')->after('local_media_paths');

            // Whether to scan subdirectories recursively
            $table->boolean('scan_recursive')->default(true)->after('metadata_source');

            // Video file extensions to scan for
            $table->jsonb('video_extensions')->nullable()->after('scan_recursive');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('media_server_integrations', function (Blueprint $table) {
            $table->dropColumn([
                'local_media_paths',
                'metadata_source',
                'scan_recursive',
                'video_extensions',
            ]);
        });
    }
};
