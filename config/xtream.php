<?php

return [
    // Default Movie and Series folders
    // These are used to create the folder structure for the strm files
    'dirs' => [
        'series' => env('XTREAM_SERIES_FOLDER', 'Series'),
        'movies' => env('XTREAM_MOVIE_FOLDER', 'Movies'),
    ],

    // Lang stripping
    'lang_strip' => [
        'EN',
        'FR',
        'ES',
        'DE',
        'IT',
        'PT',
        'NL',
        'RU',
        'AR',
        'TR',
        'PL',
        'JP',
        'CN',
    ],

    // base strm output path (inside storage/app/private/[output_path]/[playlist_id])
    'output_path' => env('XTREAM_STRM_FOLDER', 'strm'),

    // Seconds to pause all calls to a provider host after it answered HTTP 429.
    // Retrying feeds the limit; leaving the account alone is what makes it drop.
    'rate_limit_cooldown' => (int) env('XTREAM_RATE_LIMIT_COOLDOWN', 900),
];
