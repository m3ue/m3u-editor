<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cached Content
    |--------------------------------------------------------------------------
    |
    | Master switch for cached content downloads. When false, caching is off
    | regardless of the "Enable cache" setting: the settings page, Cache Now
    | actions, downloads, cleanup schedules and the cache Horizon supervisor
    | are all disabled. Only an explicit false disables it, so a blank
    | templated .env line keeps it on. config/horizon.php reads the same
    | variable.
    |
    */

    'enabled' => env('CACHED_CONTENT_ENABLED', true) !== false,

];
