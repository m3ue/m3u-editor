<?php

use App\Plugins\Contracts\ChannelProcessorPluginInterface;
use App\Plugins\Contracts\EpgProcessorPluginInterface;
use App\Plugins\Contracts\ScheduledPluginInterface;
use App\Plugins\Contracts\StreamAnalysisPluginInterface;

return [
    'api_version' => '1.0.0',

    'install_mode' => env('PLUGIN_INSTALL_MODE', 'normal'),

    'run_retention_days' => (int) env('PLUGIN_RUN_RETENTION_DAYS', 7),

    'directories' => [
        base_path('plugins'),
    ],

    'bundled_directories' => [
        base_path('plugins-bundled'),
    ],

    'dev_directories' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PLUGIN_DEV_DIRECTORIES', ''))
    ))),

    /**
     * Trusted GitHub organisations. Plugins whose plugin.json "repository"
     * field resolves to one of these orgs are auto-trusted without a manual
     * install review — equivalent to bundled plugins.
     */
    'trusted_orgs' => ['m3ue'],

    /**
     * Official plugin stubs: known plugins maintained by a trusted org.
     * The plugins:sync-official command seeds these as stub Plugin records
     * so users can browse and install them from the UI.
     */
    'official_plugins' => [
        'tv-logos' => [
            'repository' => 'm3ue/tv-logos-plugin',
            'name' => 'TV Logos',
            'description' => 'Automatically enriches channel logos from the open-source tv-logo/tv-logos repository via the jsDelivr CDN.',
        ],
        'channels-dvr' => [
            'repository' => 'm3ue/channels-dvr-plugin',
            'name' => 'Channels DVR',
            'description' => 'Maps Gracenote station IDs from a local Channels DVR install to your playlist channels via the DVR guide stations API.',
        ],
        'youtubearr' => [
            'repository' => 'm3ue/youtubearr-plugin',
            'name' => 'YouTubearr',
            'description' => 'Monitors YouTube channels for active livestreams and automatically creates or removes custom channels. Zero API quota — uses yt-dlp.',
        ],
    ],

    'staging_directory' => storage_path('app/plugin-staging'),

    'upload_directory' => env('PLUGIN_UPLOAD_DIRECTORY', 'plugin-review-uploads'),

    'review_statuses' => [
        'staged',
        'scanned',
        'review_ready',
        'approved',
        'rejected',
        'installed',
        'discarded',
    ],

    'scan_statuses' => [
        'pending',
        'clean',
        'infected',
        'scan_failed',
        'scanner_unavailable',
    ],

    'source_types' => [
        'bundled',
        'official',
        'local_directory',
        'staged_archive',
        'github_release',
        'uploaded_archive',
        'local_dev',
    ],

    'cleanup_modes' => [
        'preserve',
        'purge',
    ],

    'trust_states' => [
        'pending_review',
        'trusted',
        'blocked',
    ],

    'integrity_statuses' => [
        'unknown',
        'verified',
        'changed',
        'missing',
    ],

    'owned_storage_roots' => [
        'plugin-data',
        'plugin-reports',
    ],

    'clamav' => [
        'driver' => env('PLUGIN_SCAN_DRIVER', 'fake'), // 'clamav' or 'fake'
        'binary' => env('CLAMAV_BINARY', 'clamscan'),
        'timeout' => (int) env('CLAMAV_TIMEOUT', 60),
        'required_for_trust' => (bool) env('PLUGIN_SCAN_REQUIRED_FOR_TRUST', true),
        'fake_result' => env('PLUGIN_SCAN_FAKE_RESULT', 'clean'),
        'scan_archive_files' => (bool) env('PLUGIN_SCAN_ARCHIVES', true),
    ],

    'github' => [
        'download_timeout' => (int) env('PLUGIN_GITHUB_DOWNLOAD_TIMEOUT', 60),
        'allowed_hosts' => ['github.com'],
    ],

    'update_check' => [
        'enabled' => (bool) env('PLUGIN_UPDATE_CHECK_ENABLED', true),
        'frequency_hours' => (int) env('PLUGIN_UPDATE_CHECK_FREQUENCY', 4),
        'github_token' => env('PLUGIN_GITHUB_TOKEN'),
    ],

    'archive_limits' => [
        'max_archive_bytes' => (int) env('PLUGIN_MAX_ARCHIVE_BYTES', 50 * 1024 * 1024),
        'max_file_count' => (int) env('PLUGIN_MAX_ARCHIVE_FILES', 500),
        'max_extracted_bytes' => (int) env('PLUGIN_MAX_EXTRACTED_BYTES', 100 * 1024 * 1024),
    ],

    'permissions' => [
        'db_read' => 'Read plugin-relevant database records',
        'db_write' => 'Write plugin-relevant database records',
        'schema_manage' => 'Ask the host to create or remove plugin-owned schema',
        'filesystem_read' => 'Read plugin-owned files from storage',
        'filesystem_write' => 'Write plugin-owned files to storage',
        'network_egress' => 'Call external services or remote APIs',
        'queue_jobs' => 'Run actions, hooks, or schedules through background jobs',
        'hook_subscriptions' => 'Receive host lifecycle hook invocations',
        'scheduled_runs' => 'Run scheduled plugin actions',
    ],

    'capabilities' => [
        'channel_processor' => [
            'interface' => ChannelProcessorPluginInterface::class,
            'label' => 'Channel Processor',
            'description' => 'Process or transform channels',
        ],
        'epg_processor' => [
            'interface' => EpgProcessorPluginInterface::class,
            'label' => 'EPG Processor',
            'description' => 'Process or enrich EPG data',
        ],
        'stream_analysis' => [
            'interface' => StreamAnalysisPluginInterface::class,
            'label' => 'Stream Analysis',
            'description' => 'Analyze stream health and quality',
        ],
        'scheduled' => [
            'interface' => ScheduledPluginInterface::class,
            'label' => 'Scheduled',
            'description' => 'Run actions on a cron schedule',
        ],
    ],

    'hooks' => [
        'playlist.synced' => 'After a playlist finishes syncing',
        'epg.synced' => 'After EPG data finishes syncing',
        'epg.cache.generated' => 'After EPG cache XML files are rebuilt',
        'before.epg.map' => 'Before EPG mapping runs',
        'after.epg.map' => 'After EPG mapping runs',
        'before.epg.output.generate' => 'Before EPG output generation',
        'after.epg.output.generate' => 'After EPG output generation',
    ],

    'field_types' => [
        'boolean',
        'number',
        'text',
        'textarea',
        'tags',
        'select',
        'model_select',
    ],

    'schema_column_types' => [
        'id',
        'foreignId',
        'string',
        'text',
        'boolean',
        'integer',
        'bigInteger',
        'decimal',
        'json',
        'timestamp',
        'timestamps',
    ],

    'schema_index_types' => [
        'index',
        'unique',
    ],
];
