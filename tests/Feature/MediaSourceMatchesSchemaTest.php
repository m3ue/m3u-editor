<?php

use Illuminate\Support\Facades\DB;

it('creates the unique and index constraints on media_source_matches', function () {
    // Asserts the schema directly: chained ->unique()/->index() on
    // ->constrained() land on the ForeignKeyDefinition and are silently
    // ignored, so this guards against that regression.
    $indexes = collect(DB::select("PRAGMA index_list('media_source_matches')"))
        ->pluck('name')
        ->all();

    expect($indexes)->toContain('media_source_matches_channel_id_unique')
        ->toContain('media_source_matches_episode_id_unique')
        ->toContain('media_source_matches_media_channel_id_index')
        ->toContain('media_source_matches_media_episode_id_index')
        ->toContain('media_source_matches_playlist_id_index');
});
