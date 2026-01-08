<?php

use App\Models\Playlist;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update non-xtream API playlists to use the new sync index columns
        Playlist::where('xtream', false)->each(function (Playlist $playlist) {
            foreach ($playlist->channels()->cursor() as $channel) {
                // Set the source ID based on our composite index
                // This is a unique identifier for the channel based on its title, name, group, and playlist
                // This will help us avoid duplicates and ensure we can create a unique index
                $sourceId = md5($channel->title.$channel->name.$channel->group_internal.$playlist->id);
                $channel->update(['source_id' => $sourceId]);
            }
        });

        // Need to remove any duplicates at this point
        // This is necessary to ensure that the new unique index can be created without conflicts
        // Only process channels where source_id is set (non-xtream playlists)
        $duplicateIds = DB::table('channels')
            ->select('id')
            ->whereNotNull('source_id')
            ->whereNotIn('id', function ($query) {
                $query->select(DB::raw('MIN(id)'))
                    ->from('channels')
                    ->whereNotNull('source_id')
                    ->groupBy('source_id', 'playlist_id');
            })
            ->pluck('id');

        if ($duplicateIds->isNotEmpty()) {
            DB::table('channels')->whereIn('id', $duplicateIds)->delete();
        }

        // Create the new sync index columns
        Schema::table('channels', function (Blueprint $table) {
            $table->unique(['source_id', 'playlist_id'], 'idx_source_playlist');
            $table->dropUnique('channels_title_name_group_internal_playlist_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropUnique('idx_source_playlist');
            $table->unique(['title', 'name', 'group_internal', 'playlist_id']);
        });
    }
};
