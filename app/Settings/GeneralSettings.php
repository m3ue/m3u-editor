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

    public ?string $xtream_api_message = '';

    public ?array $xtream_api_details = null;

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

    // Default stream file setting profile IDs
    public ?int $default_series_stream_file_setting_id = null;

    public ?int $default_vod_stream_file_setting_id = null;

    public ?string $url_override = null;

    public ?bool $url_override_include_logos = true;

    public ?bool $proxy_stop_oldest_on_limit = false;

    // Failover fail conditions - mark playlists invalid on specific HTTP status codes
    public ?bool $failover_fail_conditions_enabled = false;

    public ?array $failover_fail_conditions = null;

    public ?int $failover_fail_conditions_timeout = 5;

    // Logo cache and placeholders
    public ?bool $logo_cache_permanent = false;

    public ?string $logo_placeholder_url = null;

    public ?string $episode_placeholder_url = null;

    public ?string $vod_series_poster_placeholder_url = null;

    public ?array $managed_logo_assets = null;

    public ?bool $logo_repository_enabled = false;

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

    // Series NFO file generation
    public ?bool $stream_file_sync_generate_nfo = false;

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

    // VOD NFO file generation
    public ?bool $vod_stream_file_sync_generate_nfo = false;

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

    // Provider request delay options
    public ?bool $enable_provider_request_delay = false;

    public ?int $provider_request_delay_ms = 500;

    public ?int $provider_max_concurrent_requests = 2;

    // TMDB integration settings
    public ?string $tmdb_api_key = null;

    public ?bool $tmdb_auto_lookup_on_import = false;

    public ?int $tmdb_rate_limit = 40;

    public ?string $tmdb_language = 'en-US';

    public ?int $tmdb_confidence_threshold = 80;

    // Network broadcast settings
    public ?int $broadcast_max_concurrent = 10;

    public static function group(): string
    {
        return 'general';
    }
}
