<?php

use App\Plugins\Contracts\ChannelProcessorPluginInterface;
use App\Plugins\Contracts\EpgProcessorPluginInterface;
use App\Plugins\Contracts\ScheduledPluginInterface;
use App\Plugins\Contracts\StreamAnalysisPluginInterface;

return [
    'api_version' => '1.0.0',

    'install_mode' => env('PLUGIN_INSTALL_MODE', 'normal'),

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
        'driver' => env('PLUGIN_SCAN_DRIVER', 'clamav'),
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
        'epg_processor' => EpgProcessorPluginInterface::class,
        'channel_processor' => ChannelProcessorPluginInterface::class,
        'stream_analysis' => StreamAnalysisPluginInterface::class,
        'scheduled' => ScheduledPluginInterface::class,
    ],

    'hooks' => [
        'playlist.synced',
        'epg.synced',
        'epg.cache.generated',
        'before.epg.map',
        'after.epg.map',
        'before.epg.output.generate',
        'after.epg.output.generate',
    ],

    'field_types' => [
        'boolean',
        'number',
        'text',
        'textarea',
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
