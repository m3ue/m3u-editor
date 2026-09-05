<?php

// A place to toggle experimental features on/off for testing purposes. These settings are not meant to be used in production until they are fully tested and stable.

return [
    // Flip to true for v0.12.54
    'playlist_tmdb_dynamic_groups' => env('PLAYLIST_TMDB_DYNAMIC_GROUPS', true), // Enable dynamic TMDb groups for playlists (e.g. "Trending", "Popular", etc.)
];
