<?php

namespace App\Settings;

use Filament\Support\Enums\MaxWidth;
use Spatie\LaravelSettings\Settings;

class GeneralSettings extends Settings
{
    public ?string $navigation_position = 'left';
    public ?bool $show_breadcrumbs = true;
    public ?bool $show_logs = false;
    public ?bool $show_api_docs = false;
    public ?bool $show_queue_manager = false;
    public ?string $content_width = MaxWidth::ScreenExtraLarge->value;

    // Proxy settings
    public ?string $ffmpeg_user_agent = 'VLC/3.0.21 LibVLC/3.0.21';
    public ?bool $ffmpeg_debug = false;
    public ?int $ffmpeg_max_tries = 3;
    public ?string $ffmpeg_codec_video = '';
    public ?string $ffmpeg_codec_audio = null;
    public ?string $ffmpeg_codec_subtitles = null;
    public ?string $mediaflow_proxy_url = null;
    public ?string $mediaflow_proxy_port = null;
    public ?string $mediaflow_proxy_password = null;
    public ?string $mediaflow_proxy_user_agent = null;
    public ?bool $mediaflow_proxy_playlist_user_agent = false;
    public ?string $ffmpeg_path = null;
    public ?string $ffprobe_path = null;
    public ?int $ffmpeg_hls_time = 4;
    public ?int $ffmpeg_ffprobe_timeout = 5;
    public ?int $hls_playlist_max_attempts = 10;
    public ?float $hls_playlist_sleep_seconds = 1.0;

    // VAAPI and QSV settings
    public ?string $hardware_acceleration_method = 'none';
    public ?string $ffmpeg_custom_command_template = null;
    public ?string $ffmpeg_vaapi_device = null;
    public ?string $ffmpeg_vaapi_video_filter = null;
    public ?string $ffmpeg_qsv_device = null;
    public ?string $ffmpeg_qsv_video_filter = null;
    public ?string $ffmpeg_qsv_encoder_options = null;
    public ?string $ffmpeg_qsv_additional_args = null;

    // SMTP settings
    public ?string $smtp_host = null;
    public ?int $smtp_port = null;
    public ?string $smtp_username = null;
    public ?string $smtp_password = null;
    public ?string $smtp_encryption = null; // e.g. 'tls', 'ssl', or null
    public ?string $smtp_from_address = null;

    // Stream file sync options
    public ?bool $stream_file_sync_enabled = false;
    public ?bool $stream_file_sync_include_series = false;
    public ?bool $stream_file_sync_include_season = false;
    public ?string $stream_file_sync_location = null;

    public static function group(): string
    {
        return 'general';
    }
}
