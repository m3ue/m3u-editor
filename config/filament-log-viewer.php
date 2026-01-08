<?php

declare(strict_types=1);

return [

    /* -----------------------------------------------------------------
    | Resource configuration
    | -----------------------------------------------------------------
     */
    'resource' => [
        'slug' => 'logs',
        'cluster' => null,
    ],

    /* -----------------------------------------------------------------
    |  Log files storage path
    | -----------------------------------------------------------------
     */

    'storage_path' => storage_path('logs'),

    /* -----------------------------------------------------------------
    |  Log files pattern
    | -----------------------------------------------------------------
     */

    'pattern' => [
        'prefix' => 'laravel-', // or ffmpeg-
        'date' => '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]',
        'extension' => '.log',
    ],

    /* -----------------------------------------------------------------
    |  Log entries per page
    | -----------------------------------------------------------------
    |  This defines how many logs and entries are displayed per page.
     */

    'per-page' => [
        5,
        10,
        25,
        30,
    ],

    /* -----------------------------------------------------------------
    |  Download settings
    | -----------------------------------------------------------------
     */

    'download' => [
        'prefix' => 'laravel-',

        'extension' => 'log',
    ],

    /* -----------------------------------------------------------------
    |  Icons
    | -----------------------------------------------------------------
     */

    'icons' => [
        'all' => 'fas-list', // http://fontawesome.io/icon/list/
        'emergency' => 'fas-bug', // http://fontawesome.io/icon/bug/
        'alert' => 'fas-bullhorn', // http://fontawesome.io/icon/bullhorn/
        'critical' => 'fas-heartbeat', // http://fontawesome.io/icon/heartbeat/
        'error' => 'fas-times-circle', // http://fontawesome.io/icon/times-circle/
        'warning' => 'fas-exclamation-triangle', // http://fontawesome.io/icon/exclamation-triangle/
        'notice' => 'fas-exclamation-circle', // http://fontawesome.io/icon/exclamation-circle/
        'info' => 'fas-info-circle', // http://fontawesome.io/icon/info-circle/
        'debug' => 'fas-life-ring', // http://fontawesome.io/icon/life-ring/
    ],

    /* -----------------------------------------------------------------
    |  Colors
    | -----------------------------------------------------------------
     */

    'colors' => [
        'levels' => [
            'all' => '#8A8A8A',
            'emergency' => '#B71C1C',
            'alert' => '#D32F2F',
            'critical' => '#F44336',
            'error' => '#FF5722',
            'warning' => '#FF9100',
            'notice' => '#4CAF50',
            'info' => '#1976D2',
            'debug' => '#90CAF9',
        ],
    ],

    /* -----------------------------------------------------------------
    |  Strings to highlight in stack trace
    | -----------------------------------------------------------------
     */

    'highlight' => [
        '^#\d+', '^Stack trace:',
    ],
];
