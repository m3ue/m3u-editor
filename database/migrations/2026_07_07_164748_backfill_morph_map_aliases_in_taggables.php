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
     * Backfill morph map aliases introduced in commit 539c94b22 (feat: TV app broadcast channel).
     * The morphMap added short aliases for playlist-type models, but existing taggables rows
     * still use the full class names. Without this, tags() queries find nothing for old records.
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
            // Some rows may already use the alias (stored after morph map was added).
            // Delete the full-class-name duplicate where the alias row already exists
            // to avoid the unique constraint violation on (tag_id, taggable_id, taggable_type).
            DB::table('taggables as old')
                ->where('old.taggable_type', $fullClass)
                ->whereExists(function ($query) use ($alias) {
                    $query->select(DB::raw(1))
                        ->from('taggables as new')
                        ->whereColumn('new.tag_id', 'old.tag_id')
                        ->whereColumn('new.taggable_id', 'old.taggable_id')
                        ->where('new.taggable_type', $alias);
                })
                ->delete();

            // Update remaining full-class-name rows to use the morph alias
            DB::table('taggables')
                ->where('taggable_type', $fullClass)
                ->update(['taggable_type' => $alias]);
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
            DB::table('taggables')
                ->where('taggable_type', $alias)
                ->update(['taggable_type' => $fullClass]);
        }
    }
};
