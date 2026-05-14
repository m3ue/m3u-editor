<?php

return [

    /*
    |--------------------------------------------------------------------------
    | DVR — FFmpeg binary path
    |--------------------------------------------------------------------------
    |
    | Full path to the ffmpeg executable.
    |
    */

    'ffmpeg_path' => env('DVR_FFMPEG_PATH', '/usr/bin/ffmpeg'),

    /*
    |--------------------------------------------------------------------------
    | DVR — Default storage settings
    |--------------------------------------------------------------------------
    |
    | Used when a DvrSetting row does not specify its own storage config.
    |
    */

    'storage_disk' => env('DVR_STORAGE_DISK', 'dvr'),

    'storage_path' => env('DVR_STORAGE_PATH', storage_path('app/private/dvr')),

    /*
    |--------------------------------------------------------------------------
    | DVR — Scheduler
    |--------------------------------------------------------------------------
    |
    | How many minutes ahead the scheduler looks for upcoming programmes.
    |
    */

    'scheduler_lookahead_minutes' => (int) env('DVR_SCHEDULER_LOOKAHEAD_MINUTES', 30),

    /*
    |--------------------------------------------------------------------------
    | DVR — Recording defaults
    |--------------------------------------------------------------------------
    */

    'default_start_early_seconds' => (int) env('DVR_DEFAULT_START_EARLY_SECONDS', 0),

    'default_end_late_seconds' => (int) env('DVR_DEFAULT_END_LATE_SECONDS', 0),

    'max_concurrent_recordings' => (int) env('DVR_MAX_CONCURRENT_RECORDINGS', 2),

    /*
    |--------------------------------------------------------------------------
    | DVR — Retry cap per airing
    |--------------------------------------------------------------------------
    |
    | Maximum number of StartDvrRecording attempts for a single recording row
    | before the scheduler refuses to re-schedule it within its airing window.
    | Set to 0 to disable retries entirely; null for unlimited retries.
    |
    */

    'max_attempts_per_airing' => (int) env('DVR_MAX_ATTEMPTS_PER_AIRING', 3),

    /*
    |--------------------------------------------------------------------------
    | DVR — HLS segment settings
    |--------------------------------------------------------------------------
    */

    'hls_segment_seconds' => (int) env('DVR_HLS_SEGMENT_SECONDS', 6),

    /*
    |--------------------------------------------------------------------------
    | DVR — Post-processing
    |--------------------------------------------------------------------------
    */

    'graceful_stop_timeout_seconds' => (int) env('DVR_GRACEFUL_STOP_TIMEOUT_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | DVR — Metadata enrichment
    |--------------------------------------------------------------------------
    */

    'tmdb_api_key' => env('DVR_TMDB_API_KEY'),

    'tmdb_base_url' => env('DVR_TMDB_BASE_URL', 'https://api.themoviedb.org/3'),

    'tvmaze_base_url' => env('DVR_TVMAZE_BASE_URL', 'https://api.tvmaze.com'),

    /*
    |--------------------------------------------------------------------------
    | DVR — Stream base URL
    |--------------------------------------------------------------------------
    |
    | Base URL used when building DVR recording stream URLs for VOD integration.
    | This should be the URL that the proxy (or any internal service) uses to
    | reach the editor — typically the Docker-internal service hostname when
    | running in a containerised stack (e.g. http://m3u-local-editor:36460).
    |
    | When empty, falls back to PlaylistService::getBaseUrl() which respects
    | the url_override general setting and APP_URL.
    |
    */

    'stream_base_url' => env('DVR_STREAM_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | DVR — Comskip (commercial detection)
    |--------------------------------------------------------------------------
    */

    'comskip_path' => env('DVR_COMSKIP_PATH', '/usr/local/bin/comskip'),

    'comskip_default_ini' => env('DVR_COMSKIP_INI', config_path('comskip.default.ini')),

];
