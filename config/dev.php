<?php

return [
    'author' => 'Shaun Parkison',
    'version' => '0.12.52',
    'dev_version' => '0.12.52-dev',
    'experimental_version' => '0.12.52-exp',
    'repo' => 'm3ue/m3u-editor',
    'docs_url' => 'https://m3ue.sparkison.dev',
    'donate' => 'https://buymeacoffee.com/shparkison',
    'discord_url' => 'https://discord.gg/rS3abJ5dz7',
    'tv_repo' => 'm3ue/m3u-tv',
    'tv_releases_url' => 'https://github.com/m3ue/m3u-tv#m3u-tv', // 'https://github.com/m3ue/m3u-tv/releases',
    'paypal' => 'https://www.paypal.com/donate/?hosted_button_id=ULJRPVWJNBSSG',
    'kofi' => 'https://ko-fi.com/sparkison',
    'admin_emails' => ['admin@test.com'],
    'tvgid' => [
        'regex' => env('TVGID_REGEX', '/[^a-zA-Z0-9_\-\.]/'),
    ],
    'timezone' => env('TZ', null), // Override application timezone (e.g. "America/Detroit"). Leave empty to use server default (UTC).
    'disable_sync_logs' => env('DISABLE_SYNC_LOGS', false), // Disable sync logs for performance
    'max_channels' => env('MAX_CHANNELS', 50000), // Maximum number of channels allowed for m3u import
    'invalidate_import' => env('INVALIDATE_IMPORT', null), // Invalidate import if number of "new" channels is less than the current count (minus `INVALIDATE_IMPORT_THRESHOLD`)
    'invalidate_import_threshold' => env('INVALIDATE_IMPORT_THRESHOLD', null), // Threshold for invalidating import
    'invalidate_import_series_threshold' => env('INVALIDATE_IMPORT_SERIES_THRESHOLD', null), // Threshold for invalidating import based on series removal count
    'invalidate_import_group_threshold' => env('INVALIDATE_IMPORT_GROUP_THRESHOLD', null), // Threshold for invalidating import based on group/category removal count
    'default_epg_days' => env('DEFAULT_EPG_DAYS', 7), // Default number of days to fetch for EPG generation
    'default_epg_catchup_days' => env('DEFAULT_EPG_CATCHUP_DAYS', 7), // Fallback tv_archive_duration (days) reported when catchup is enabled but no duration is known; 0 disables the fallback (reports no retention)
    'show_wan_details' => env('SHOW_WAN_DETAILS', null), // Show WAN details in admin panel
    'stuck_processing_minutes' => env('STUCK_PROCESSING_MINUTES', 240),
    'failed_retry_cooldown_minutes' => env('FAILED_RETRY_COOLDOWN_MINUTES', 15),
    'auto_retry_503_enabled' => env('AUTO_RETRY_503_ENABLED', true),
    'auto_retry_503_max' => env('AUTO_RETRY_503_MAX', 3),
    'auto_retry_503_cooldown_minutes' => env('AUTO_RETRY_503_COOLDOWN_MINUTES', 10),
    'auto_retry_503_delay_min_seconds' => env('AUTO_RETRY_503_DELAY_MIN_SECONDS', 300),
    'auto_retry_503_delay_max_seconds' => env('AUTO_RETRY_503_DELAY_MAX_SECONDS', 900),

    // restrict playlists to specific domains (comma separated list, supports wildcards, e.g. *.example.com)
    'allowed_playlist_domains' => env('ALLOWED_PLAYLIST_DOMAINS', null),

    // Timeout (seconds) for downloading the playlist file itself from the provider. Large
    // playlists (many VOD entries) on throttled/slow connections can take a while to fully
    // transfer; this covers the whole request (connect + transfer), not just the connect phase.
    'playlist_download_timeout' => env('PLAYLIST_DOWNLOAD_TIMEOUT', 900),
    'epg_download_timeout' => env('EPG_DOWNLOAD_TIMEOUT', 900),
];
