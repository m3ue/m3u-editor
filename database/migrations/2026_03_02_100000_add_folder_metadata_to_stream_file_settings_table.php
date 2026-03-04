<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stream_file_settings', function (Blueprint $table) {
            $table->jsonb('folder_metadata')->nullable()->after('filename_metadata');
        });

        // Migrate existing VOD records that have a title folder:
        // - Move 'tmdb_id' from filename_metadata → folder_metadata
        // - Always add 'year' to folder_metadata (was previously hardcoded in the sync job)
        DB::table('stream_file_settings')
            ->where('type', 'vod')
            ->get()
            ->each(function ($setting) {
                $pathStructure = json_decode($setting->path_structure ?? '[]', true) ?? [];

                if (! in_array('title', $pathStructure)) {
                    return;
                }

                $filenameMetadata = json_decode($setting->filename_metadata ?? '[]', true) ?? [];
                $folderMetadata = ['year']; // Year was always added to folder (hardcoded)

                if (in_array('tmdb_id', $filenameMetadata)) {
                    $folderMetadata[] = 'tmdb_id';
                    $filenameMetadata = array_values(array_filter($filenameMetadata, fn ($v) => $v !== 'tmdb_id'));
                }

                DB::table('stream_file_settings')->where('id', $setting->id)->update([
                    'folder_metadata' => json_encode($folderMetadata),
                    'filename_metadata' => json_encode($filenameMetadata),
                ]);
            });
    }

    public function down(): void
    {
        // Reverse migration: move tmdb_id back from folder_metadata to filename_metadata
        DB::table('stream_file_settings')
            ->where('type', 'vod')
            ->whereNotNull('folder_metadata')
            ->get()
            ->each(function ($setting) {
                $folderMetadata = json_decode($setting->folder_metadata ?? '[]', true) ?? [];
                $filenameMetadata = json_decode($setting->filename_metadata ?? '[]', true) ?? [];

                if (in_array('tmdb_id', $folderMetadata) && ! in_array('tmdb_id', $filenameMetadata)) {
                    $filenameMetadata[] = 'tmdb_id';
                    DB::table('stream_file_settings')->where('id', $setting->id)->update([
                        'filename_metadata' => json_encode(array_values($filenameMetadata)),
                    ]);
                }
            });

        Schema::table('stream_file_settings', function (Blueprint $table) {
            $table->dropColumn('folder_metadata');
        });
    }
};
