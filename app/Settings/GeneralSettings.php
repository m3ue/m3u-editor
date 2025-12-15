<?php

namespace App\Settings;

use Filament\Support\Enums\Width;
use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    // General UI settings
    public ?string $navigation_position = 'left';
    public ?bool $show_breadcrumbs = true;
    public ?bool $show_logs = false;
    public ?bool $show_api_docs = false;
    public ?bool $show_queue_manager = false;
    public ?string $content_width = Width::ScreenExtraLarge->value;
    public ?bool $output_wan_address = false;


    // MediaFlow proxy settings
    public ?string $mediaflow_proxy_url = null;
    public ?string $mediaflow_proxy_port = null;
    public ?string $mediaflow_proxy_password = null;
    public ?string $mediaflow_proxy_user_agent = null;
    public ?bool $mediaflow_proxy_playlist_user_agent = false;


    // M3U Proxy settings
    public ?bool $enable_failover_resolver = false;
    public ?string $failover_resolver_url = null;
    public ?int $default_stream_profile_id = null;
    public ?int $default_vod_stream_profile_id = null;
    public ?string $url_override = null;
    public ?bool $url_override_include_logos = true;


    // SMTP settings
    public ?string $smtp_host = null;
    public ?int $smtp_port = null;
    public ?string $smtp_username = null;
    public ?string $smtp_password = null;
    public ?string $smtp_encryption = null; // e.g. 'tls', 'ssl', or null
    public ?string $smtp_from_address = null;


    // Stream file sync options
    public ?bool $stream_file_sync_enabled = false;
    public ?bool $stream_file_sync_include_category = false;
    public ?bool $stream_file_sync_include_series = false;
    public ?bool $stream_file_sync_include_season = false;
    public ?string $stream_file_sync_location = null;
    public ?array $stream_file_sync_path_structure = null;


    // Stream file sync filename options
    public ?array $stream_file_sync_filename_metadata = null;
    public ?bool $stream_file_sync_filename_year = false;
    public ?bool $stream_file_sync_filename_resolution = false;
    public ?bool $stream_file_sync_filename_codec = false;
    public ?bool $stream_file_sync_filename_tmdb_id = false;
    public ?string $stream_file_sync_tmdb_id_format = 'square';
    public ?bool $stream_file_sync_clean_special_chars = true;
    public ?bool $stream_file_sync_remove_consecutive_chars = true;
    public ?string $stream_file_sync_replace_char = 'space';


    // Stream file sync name filtering options
    public ?bool $stream_file_sync_name_filter_enabled = false;
    public ?array $stream_file_sync_name_filter_patterns = null;


    // VOD stream file sync options
    public ?bool $vod_stream_file_sync_enabled = false;
    public ?bool $vod_stream_file_sync_include_series = false;
    public ?bool $vod_stream_file_sync_include_season = false;
    public ?string $vod_stream_file_sync_location = null;
    public ?array $vod_stream_file_sync_path_structure = null;


    // VOD stream file sync filename options
    public ?array $vod_stream_file_sync_filename_metadata = null;
    public ?bool $vod_stream_file_sync_filename_year = false;
    public ?bool $vod_stream_file_sync_filename_resolution = false;
    public ?bool $vod_stream_file_sync_filename_codec = false;
    public ?bool $vod_stream_file_sync_filename_tmdb_id = false;
    public ?string $vod_stream_file_sync_tmdb_id_format = 'square';
    public ?bool $vod_stream_file_sync_clean_special_chars = true;
    public ?bool $vod_stream_file_sync_remove_consecutive_chars = true;
    public ?string $vod_stream_file_sync_replace_char = 'space';


    // VOD stream file sync name filtering options
    public ?bool $vod_stream_file_sync_name_filter_enabled = false;
    public ?array $vod_stream_file_sync_name_filter_patterns = null;


    // Video player proxy options
    public ?bool $force_video_player_proxy = false;
    // Resolve m3u-proxy public URL at request time when not explicitly configured
    public ?bool $m3u_proxy_public_url_auto_resolve = false;


    // Sync options
    public ?bool $invalidate_import = false;
    public ?int $invalidate_import_threshold = 100;


    // Backup options
    public ?bool $auto_backup_database = false;
    public ?string $auto_backup_database_schedule = null;
    public ?int $auto_backup_database_max_backups = 5;


    public static function group(): string
    {
        return 'general';
    }
}
