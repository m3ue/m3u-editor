<?php

use App\Models\CustomPlaylist;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Backfill morph map aliases in the playlist_viewers table.
     * The morphMap added short aliases for playlist-type models, but existing rows
     * still use the full class names, breaking morphTo() resolution and WHERE queries.
     */
    public function up(): void
    {
        $map = [
            Playlist::class => 'playlist',
            MergedPlaylist::class => 'merged_playlist',
            CustomPlaylist::class => 'custom_playlist',
            PlaylistAlias::class => 'alias',
        ];

        foreach ($map as $fullClass => $alias) {
            DB::table('playlist_viewers')
                ->where('viewerable_type', $fullClass)
                ->update(['viewerable_type' => $alias]);
        }
    }

    public function down(): void
    {
        $map = [
            'playlist' => Playlist::class,
            'merged_playlist' => MergedPlaylist::class,
            'custom_playlist' => CustomPlaylist::class,
            'alias' => PlaylistAlias::class,
        ];

        foreach ($map as $alias => $fullClass) {
            DB::table('playlist_viewers')
                ->where('viewerable_type', $alias)
                ->update(['viewerable_type' => $fullClass]);
        }
    }
};
