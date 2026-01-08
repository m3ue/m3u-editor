<?php

use App\Models\Playlist;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Playlist::where('xtream', false)->cursor()->each(function (Playlist $playlist) {
            // Update source_id for channels in non-Xtream playlists
            $playlist->channels()->cursor()->each(function ($channel) {
                // Need to remove the Playlist ID as we don't need it for uniqueness,
                // and it's preventing comparing the same streams from other playlists.
                $channel->source_id = md5($channel->title.$channel->name.$channel->group_internal);
                $channel->save();
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
