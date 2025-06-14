<?php

namespace App\Services;

use App\Settings\GeneralSettings;
use Exception;

class ProxyService
{
    // Cache configuration for bad sources
    public const BAD_SOURCE_CACHE_SECONDS = 10;
    public const BAD_SOURCE_CACHE_PREFIX = 'failover:bad_source:';

    /**
     * Get the proxy URL for a channel
     *
     * @param string|int $id
     * @param string $format
     * @return string
     */
    public function getProxyUrlForChannel($id, $format = 'ts', $preview = false)
    {
        $proxyUrlOverride = config('proxy.url_override');
        $proxyFormat = $format ?? config('proxy.proxy_format', 'ts');
        $id = rtrim(base64_encode($id), '=');
        if ($proxyUrlOverride && !$preview) {
            $proxyUrlOverride = rtrim($proxyUrlOverride, '/');
            if ($proxyFormat === 'hls') {
                return "$proxyUrlOverride/api/stream/$id/playlist.m3u8";
            } else {
                return "$proxyUrlOverride/stream/$id.ts";
            }
        }

        return $proxyFormat === 'hls'
            ? route('stream.hls.playlist', [
                'encodedId' => $id
            ])
            : route('stream', [
                'encodedId' => $id,
                'format' => $format
            ]);
    }

    /**
     * Get the proxy URL for an episode
     *
     * @param string|int $id
     * @param string $format
     * @return string
     */
    public function getProxyUrlForEpisode($id, $format = 'ts', $preview = false)
    {
        $proxyUrlOverride = config('proxy.url_override');
        $proxyFormat = $format ?? config('proxy.proxy_format', 'ts');
        $id = rtrim(base64_encode($id), '=');
        if ($proxyUrlOverride && !$preview) {
            $proxyUrlOverride = rtrim($proxyUrlOverride, '/');
            if ($proxyFormat === 'hls') {
                return "$proxyUrlOverride/api/stream/e/$id/playlist.m3u8";
            } else {
                return "$proxyUrlOverride/stream/e/$id.ts";
            }
        }

        return $proxyFormat === 'hls'
            ? route('stream.hls.episode', [
                'encodedId' => $id
            ])
            : route('stream.episode', [
                'encodedId' => $id,
                'format' => $format
            ]);
    }

    /**
     * Determine the video codec to use based on configuration and settings.
     *
     * @param string|null $codecFromConfig
     * @param string|null $codecFromSettings
     * @return string
     */
    public static function determineVideoCodec(?string $codecFromConfig, ?string $codecFromSettings): string
    {
        if ($codecFromConfig !== null && $codecFromConfig !== '') {
            return $codecFromConfig;
        } elseif ($codecFromSettings !== null && $codecFromSettings !== '') {
            return $codecFromSettings;
        } else {
            return 'copy'; // Default to 'copy'
        }
    }

    /**
     * Get all settings needed for streaming
     */
    public static function getStreamSettings(): array
    {
        $userPreferences = app(GeneralSettings::class);
        $settings = [
            'ffmpeg_debug' => false,
            'ffmpeg_max_tries' => 3,
            'ffmpeg_user_agent' => 'VLC/3.0.21 LibVLC/3.0.21',
            'ffmpeg_codec_video' => 'copy',
            'ffmpeg_codec_audio' => 'copy',
            'ffmpeg_codec_subtitles' => 'copy',
            'ffmpeg_path' => 'jellyfin-ffmpeg',

            // HW acceleration settings
            'hardware_acceleration_method' => 'none',
            'ffmpeg_custom_command_template' => null,
            'ffmpeg_vaapi_device' => '/dev/dri/renderD128',
            'ffmpeg_vaapi_video_filter' => '',
            'ffmpeg_qsv_device' => '/dev/dri/renderD128',
            'ffmpeg_qsv_video_filter' => '',
            'ffmpeg_qsv_encoder_options' => null,
            'ffmpeg_qsv_additional_args' => null,
        ];

        try {
            $settings = [
                // General settings
                'ffmpeg_debug' => $userPreferences->ffmpeg_debug ?? $settings['ffmpeg_debug'],
                'ffmpeg_max_tries' => $userPreferences->ffmpeg_max_tries ?? $settings['ffmpeg_max_tries'],
                'ffmpeg_user_agent' => $userPreferences->ffmpeg_user_agent ?? $settings['ffmpeg_user_agent'],
                'ffmpeg_codec_video' => $userPreferences->ffmpeg_codec_video ?? $settings['ffmpeg_codec_video'],
                'ffmpeg_codec_audio' => $userPreferences->ffmpeg_codec_audio ?? $settings['ffmpeg_codec_audio'],
                'ffmpeg_codec_subtitles' => $userPreferences->ffmpeg_codec_subtitles ?? $settings['ffmpeg_codec_subtitles'],
                'ffmpeg_path' => $userPreferences->ffmpeg_path ?? $settings['ffmpeg_path'],
                'ffmpeg_hls_time' => $userPreferences->ffmpeg_hls_time ?? 4,
                'ffmpeg_ffprobe_timeout' => $userPreferences->ffmpeg_ffprobe_timeout ?? 5,

                // HW acceleration settings
                'hardware_acceleration_method' => $userPreferences->hardware_acceleration_method ?? $settings['hardware_acceleration_method'],
                'ffmpeg_custom_command_template' => $userPreferences->ffmpeg_custom_command_template ?? $settings['ffmpeg_custom_command_template'],

                // Add VA-API settings
                'ffmpeg_vaapi_device' => $userPreferences->ffmpeg_vaapi_device ?? $settings['ffmpeg_vaapi_device'],
                'ffmpeg_vaapi_video_filter' => $userPreferences->ffmpeg_vaapi_video_filter ?? $settings['ffmpeg_vaapi_video_filter'],

                // Add QSV settings
                'ffmpeg_qsv_device' => $userPreferences->ffmpeg_qsv_device ?? $settings['ffmpeg_qsv_device'],
                'ffmpeg_qsv_video_filter' => $userPreferences->ffmpeg_qsv_video_filter ?? $settings['ffmpeg_qsv_video_filter'],
                'ffmpeg_qsv_encoder_options' => $userPreferences->ffmpeg_qsv_encoder_options ?? $settings['ffmpeg_qsv_encoder_options'],
                'ffmpeg_qsv_additional_args' => $userPreferences->ffmpeg_qsv_additional_args ?? $settings['ffmpeg_qsv_additional_args'],
            ];

            // Add any additional args from config
            $settings['ffmpeg_additional_args'] = config('proxy.ffmpeg_additional_args', '');
        } catch (Exception $e) {
            // Ignore
        }

        // Set hardware acceleration flags based on the method
        $settings['ffmpeg_vaapi_enabled'] = $settings['hardware_acceleration_method'] === 'vaapi';
        $settings['ffmpeg_qsv_enabled'] = $settings['hardware_acceleration_method'] === 'qsv';

        return $settings;
    }
}
