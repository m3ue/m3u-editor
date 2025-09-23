<?php

namespace App\Services;

use App\Settings\GeneralSettings;
use Carbon\Carbon;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ProxyService
{
    // Cache configuration for bad sources
    public const BAD_SOURCE_CACHE_SECONDS = 10; // Default for ffprobe/general errors
    public const BAD_SOURCE_CACHE_SECONDS_GENERAL_ERROR = 5; // For general ffprobe errors
    public const BAD_SOURCE_CACHE_SECONDS_CONTENT_ERROR = 10; // For fatal stream content errors
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
                return "$proxyUrlOverride/shared/stream/$id.m3u8";
            } else {
                return "$proxyUrlOverride/shared/stream/$id.ts";
            }
        }

        return route('shared.stream.channel', [
            'encodedId' => $id,
            'format' => $proxyFormat === 'hls' ? 'm3u8' : $format
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
                return "$proxyUrlOverride/shared/stream/e/$id.m3u8";
            } else {
                return "$proxyUrlOverride/shared/stream/e/$id.ts";
            }
        }

        return route('shared.stream.episode', [
            'encodedId' => $id,
            'format' => $proxyFormat === 'hls' ? 'm3u8' : $format
        ]);
    }

    /**
     * Generate a timeshift URL for a given stream.
     *
     * @param \Illuminate\Http\Request $request
     * @param string $streamUrl
     * @param Playlist|MergedPlaylist|CustomPlaylist|PlaylistAlias $playlist
     * 
     * @return string
     */
    public static function generateTimeshiftUrl(Request $request, string $streamUrl, $playlist)
    {
        /* ── Timeshift SETUP (TiviMate → portal format) ───────────────────── */
        // TiviMate sends utc/lutc as UNIX epochs (UTC). We only convert TZ + format.
        $utcPresent = $request->filled('utc');
        $xtreamTimeshiftPresent = $request->filled('timeshift_duration') && $request->filled('timeshift_date');

        if ($utcPresent && !$xtreamTimeshiftPresent) {
            $utc = (int) $request->query('utc'); // programme start (UTC epoch)
            $lutc = (int) ($request->query('lutc') ?? time()); // “live” now (UTC epoch)

            // duration (minutes) from start → now; ceil avoids off-by-one near edges
            $offset = max(1, (int) ceil(max(0, $lutc - $utc) / 60));

            // "…://host/live/u/p/<id>.<ext>" >>> "…://host/streaming/timeshift.php?username=u&password=p&stream=id&start=stamp&duration=offset"
            $rewrite = static function (string $url, string $stamp, int $offset): string {
                if (preg_match('~^(https?://[^/]+)/live/([^/]+)/([^/]+)/([^/]+)\.[^/]+$~', $url, $m)) {
                    [$_, $base, $user, $pass, $id] = $m;
                    return sprintf(
                        '%s/streaming/timeshift.php?username=%s&password=%s&stream=%s&start=%s&duration=%d',
                        $base,
                        $user,
                        $pass,
                        $id,
                        $stamp,
                        $offset
                    );
                }
                return $url; // fallback if pattern does not match
            };
        } elseif ($xtreamTimeshiftPresent) {
            // Handle Xtream API timeshift format
            $duration = (int) $request->get('timeshift_duration'); // Duration in minutes
            $date = $request->get('timeshift_date'); // Format: YYYY-MM-DD:HH-MM-SS

            // "…://host/live/u/p/<id>.<ext>" >>> "…://host/timeshift/u/p/duration/stamp/<id>.<ext>"
            $rewrite = static function (string $url, string $stamp, int $offset): string {
                if (preg_match('~^(https?://[^/]+)/live/([^/]+)/([^/]+)/([^/]+)\.([^/]+)$~', $url, $m)) {
                    [$_, $base, $user, $pass, $id, $ext] = $m;
                    return sprintf(
                        '%s/timeshift/%s/%s/%d/%s/%s.%s',
                        $base,
                        $user,
                        $pass,
                        $offset,
                        $stamp,
                        $id,
                        $ext
                    );
                }
                return $url; // fallback if pattern does not match
            };
        }
        /* ─────────────────────────────────────────────────────────────────── */

        // ── Apply timeshift rewriting AFTER we know the provider timezone ──
        if ($utcPresent && !$xtreamTimeshiftPresent) {
            // Use the portal/provider timezone (DST-aware). Prefer per-playlist; last resort UTC.
            $providerTz = $playlist?->server_timezone ?? 'Etc/UTC';

            // Convert the absolute UTC epoch from TiviMate to provider-local time string expected by timeshift.php
            $stamp = Carbon::createFromTimestampUTC($utc)
                ->setTimezone($providerTz)
                ->format('Y-m-d:H-i');

            $streamUrl = $rewrite($streamUrl, $stamp, $offset);

            // Helpful debug for verification
            Log::debug(sprintf(
                '[TIMESHIFT-M3U] utc=%d lutc=%d tz=%s start=%s offset(min)=%d final_url=%s',
                $utc,
                $lutc,
                $providerTz,
                $stamp,
                $offset,
                $streamUrl
            ));
        } elseif ($xtreamTimeshiftPresent) {
            // Convert Xtream API date format to timeshift URL format
            // Input: YYYY-MM-DD:HH-MM-SS, Output: YYYY-MM-DD:HH-MM
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2}):(\d{2})-(\d{2})-(\d{2})$/', $date, $matches)) {
                $stamp = sprintf('%s-%s-%s:%s-%s', $matches[1], $matches[2], $matches[3], $matches[4], $matches[5]);
            } else {
                // If the format doesn't match expected pattern, try to clean it up
                $stamp = preg_replace('/[^\d\-:]/', '', $date);
                $stamp = preg_replace('/:(\d{2})$/', '', $stamp); // Remove seconds if present
            }

            $streamUrl = $rewrite($streamUrl, $stamp, $duration);

            // Helpful debug for verification
            Log::debug(sprintf(
                '[TIMESHIFT-XTREAM] duration=%d date=%s converted_stamp=%s final_url=%s',
                $duration,
                $date,
                $stamp,
                $streamUrl
            ));
        }

        return $streamUrl;
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
            'ffprobe_path' => 'jellyfin-ffprobe', // Default ffprobe path

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
            // Apply any user overrides from the GeneralSettings
            $settings = array_merge($settings, $userPreferences->toArray());

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

    /**
     * Determine the effective ffprobe executable path.
     *
     * @param array $settings The stream settings array from getStreamSettings().
     * @return string The ffprobe executable path/command.
     */
    public static function getEffectiveFfprobePath(array $settings): string
    {
        $envFfprobePath = config('proxy.ffprobe_path');
        if (!empty($envFfprobePath)) {
            // Handle keywords or direct path from env var
            if ($envFfprobePath === 'jellyfin-ffprobe' || $envFfprobePath === 'ffprobe') {
                return $envFfprobePath;
            }
            return $envFfprobePath; // Assume full path
        }

        // Use the ffprobe_path from GeneralSettings (actual user setting from DB)
        // This ensures we respect a user's explicit choice of null/empty if they want derivation.
        $userFfprobePath = app(GeneralSettings::class)->ffprobe_path;
        if (!empty($userFfprobePath)) {
            // Handle keywords or direct path from user setting
            if ($userFfprobePath === 'jellyfin-ffprobe' || $userFfprobePath === 'ffprobe') {
                return $userFfprobePath;
            }
            return $userFfprobePath; // Assume full path
        }

        // If both env and user settings for ffprobe are empty, then derive or use default.
        // $settings['ffmpeg_path'] from getStreamSettings() already reflects env -> user_db -> service_default for ffmpeg.
        $ffmpegPath = $settings['ffmpeg_path'] ?? 'jellyfin-ffmpeg'; // Default to 'jellyfin-ffmpeg' if not in settings for some reason
        if ($ffmpegPath === 'jellyfin-ffmpeg') {
            return 'jellyfin-ffprobe';
        } elseif (str_contains($ffmpegPath, '/')) {
            // If ffmpeg_path is a full path like /usr/bin/ffmpeg, derive ffprobe path e.g. /usr/bin/ffprobe
            return dirname($ffmpegPath) . '/ffprobe';
        } else {
            // Default to 'ffprobe' for other simple ffmpeg names like 'ffmpeg' or if $ffmpegPath was empty and defaulted above.
            return 'ffprobe';
        }
    }

    /**
     * Build the FFmpeg command for HLS streaming.
     *
     * @param string $m3uPlaylist
     * @param string $segment
     * @param string $segmentBaseUrl
     * @param string $storageDir
     * @return string
     */
    public static function buildHlsCommand(
        string $m3uPlaylist,
        string $segment,
        string $segmentBaseUrl,
        string $storageDir,
        string $userAgent,
        string $streamUrl,
    ): string {
        // Get default stream settings
        $settings = self::getStreamSettings();
        $customCommandTemplate = $settings['ffmpeg_custom_command_template'] ?? null;

        // Get ffmpeg path
        $ffmpegPath = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
        if (empty($ffmpegPath)) {
            $ffmpegPath = 'jellyfin-ffmpeg';
        }

        // Determine the effective video codec based on config and settings
        $finalVideoCodec = self::determineVideoCodec(
            config('proxy.ffmpeg_codec_video', null),
            $settings['ffmpeg_codec_video'] ?? 'copy' // Default to 'copy' if not set
        );

        // Initialize Hardware Acceleration and Codec Specific Argument Variables
        $hwaccelInitArgs = '';    // For -init_hw_device
        $hwaccelInputArgs = '';   // For -hwaccel options before input (e.g., -hwaccel vaapi -hwaccel_output_format vaapi)
        $videoFilterArgs = '';    // For -vf
        $codecSpecificArgs = '';  // For encoder options like -profile:v, -preset, etc.
        $outputVideoCodec = $finalVideoCodec; // This might be overridden by hw accel logic

        // Get user defined options
        $userArgs = config('proxy.ffmpeg_additional_args', '');
        if (!empty($userArgs)) {
            $userArgs .= ' ';
        }

        // Command construction logic
        if (empty($customCommandTemplate)) {
            // VA-API Settings from GeneralSettings
            $vaapiEnabled = (($settings['hardware_acceleration_method'] ?? 'none') === 'vaapi');
            $vaapiDevice = escapeshellarg($settings['ffmpeg_vaapi_device'] ?? '/dev/dri/renderD128');
            $vaapiFilterFromSettings = $settings['ffmpeg_vaapi_video_filter'] ?? '';

            // QSV Settings from GeneralSettings
            $qsvEnabled = (($settings['hardware_acceleration_method'] ?? 'none') === 'qsv');
            $qsvDevice = escapeshellarg($settings['ffmpeg_qsv_device'] ?? '/dev/dri/renderD128');
            $qsvFilterFromSettings = $settings['ffmpeg_qsv_video_filter'] ?? '';
            $qsvEncoderOptions = $settings['ffmpeg_qsv_encoder_options'] ?? null;
            $qsvAdditionalArgs = $settings['ffmpeg_qsv_additional_args'] ?? null;

            $isVaapiCodec = str_contains($finalVideoCodec, '_vaapi');
            $isQsvCodec = str_contains($finalVideoCodec, '_qsv');

            // Get ffmpeg output codec formats
            $audioCodec = config('proxy.ffmpeg_codec_audio') ?: $settings['ffmpeg_codec_audio']; // This is the target codec
            $subtitleCodec = config('proxy.ffmpeg_codec_subtitles') ?: $settings['ffmpeg_codec_subtitles'];
            $audioParams = '';

            if ($vaapiEnabled || $isVaapiCodec) {
                $outputVideoCodec = $isVaapiCodec ? $finalVideoCodec : 'h264_vaapi'; // Default to h264_vaapi if only toggle is on
                $hwaccelInitArgs = "-init_hw_device vaapi=va_device:{$vaapiDevice} -filter_hw_device va_device ";

                // These args are for full hardware acceleration (decode using VA-API)
                $hwaccelInputArgs = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";
                if (!empty($vaapiFilterFromSettings)) {
                    $videoFilterArgs = "-vf '" . trim($vaapiFilterFromSettings, "'") . "' ";
                } else {
                    $videoFilterArgs = ""; // No default -vf filter
                }
                // If $vaapiFilterFromSettings is empty, no -vf is added here for VA-API.
                // FFmpeg will handle conversions if possible, or fail if direct path isn't supported.
            } elseif ($qsvEnabled || $isQsvCodec) {
                // Only apply QSV if VA-API wasn't chosen/enabled
                $outputVideoCodec = $isQsvCodec ? $finalVideoCodec : 'h264_qsv'; // Default to h264_qsv
                $qsvDeviceName = 'qsv_hw'; // Internal FFmpeg label
                $hwaccelInitArgs = "-init_hw_device qsv={$qsvDeviceName}:{$qsvDevice} ";

                // These args are for full hardware acceleration (decode using QSV)
                $hwaccelInputArgs = "-hwaccel qsv -hwaccel_device {$qsvDeviceName} -hwaccel_output_format qsv ";
                if (!empty($qsvFilterFromSettings)) {
                    // This filter is applied to frames already in QSV format
                    $videoFilterArgs = "-vf '" . trim($qsvFilterFromSettings, "'") . "' ";
                } else {
                    // Add default QSV video filter for HLS if not set by user
                    $videoFilterArgs = "-vf 'hwupload=extra_hw_frames=64,scale_qsv=format=nv12' ";
                }
                if (!empty($qsvEncoderOptions)) { // $qsvEncoderOptions = $settings['ffmpeg_qsv_encoder_options']
                    $codecSpecificArgs = trim($qsvEncoderOptions) . " ";
                } else {
                    // Default QSV encoder options
                    $codecSpecificArgs = "-preset medium ";

                    // Only add -global_quality if NOT using libopus
                    if ($audioCodec !== 'libopus') { // $audioCodec here is the one determined at the top of buildCmd
                        $codecSpecificArgs .= "-global_quality 23 ";
                    }
                }
                if (!empty($qsvAdditionalArgs)) {
                    $userArgs = trim($qsvAdditionalArgs) . ($userArgs ? " " . $userArgs : "");
                }
            }

            // If neither VA-API nor QSV is applicable, $outputVideoCodec uses $finalVideoCodec (e.g. libx264 or copy)
            // and $hwaccelInitArgs, $hwaccelInputArgs, $videoFilterArgs remain empty from hw accel logic.
            if ($audioCodec === 'opus') {
                $audioCodec = 'libopus'; // Ensure we use libopus
                Log::channel('ffmpeg')->debug("HLS: Switched audio codec from 'opus' to 'libopus'.");
            }

            if ($audioCodec === 'libopus' && $audioCodec !== 'copy') {
                $audioParams = " -vbr 1";
                // Check if user already specified a bitrate in global args, if not, add default
                if (strpos($userArgs, '-b:a') === false) {
                    $audioParams .= " -b:a 128k";
                }
                Log::channel('ffmpeg')->debug("HLS: Setting VBR and bitrate for libopus. Params: {$audioParams}");
            } elseif (($audioCodec === 'vorbis' || $audioCodec === 'libvorbis') && $audioCodec !== 'copy') {
                $audioParams = ' -strict -2';
                Log::channel('ffmpeg')->debug("HLS: Setting -strict -2 for vorbis.");
            }

            // Start building ffmpeg output codec formats
            $outputFormat = "-c:v {$outputVideoCodec} " .
                ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "");

            // Conditionally add audio codec
            if (!empty($audioCodec)) {
                $outputFormat .= "-c:a {$audioCodec}{$audioParams} ";
            }

            // Conditionally add subtitle codec
            if (!empty($subtitleCodec)) {
                $outputFormat .= "-c:s {$subtitleCodec} ";
            }
            $outputFormat = trim($outputFormat); // Trim trailing space

            // Reconstruct FFmpeg Command (ensure $ffmpegPath is escaped if it can contain spaces, though unlikely for a binary name)
            $cmd = escapeshellcmd($ffmpegPath) . ' ';
            $cmd .= $hwaccelInitArgs;  // e.g., -init_hw_device (goes before input options that use it, but after global options)
            $cmd .= $hwaccelInputArgs; // e.g., -hwaccel vaapi (these must go BEFORE the -i input)

            // Low-latency flags for better HLS performance
            $cmd .= '-fflags nobuffer+igndts -flags low_delay -avoid_negative_ts disabled -copyts -start_at_zero ';

            // Input analysis optimization for faster stream start
            $cmd .= '-analyzeduration 1M -probesize 1M -max_delay 500000 -fpsprobesize 0 ';

            // Better error handling
            $cmd .= '-err_detect ignore_err -ignore_unknown -fflags +discardcorrupt ';

            // Use the user agent from settings, escape it. $userAgent parameter is ignored for now.
            $effectiveUserAgent = $userAgent ?: $settings['ffmpeg_user_agent'];
            $cmd .= "-user_agent " . escapeshellarg($effectiveUserAgent) . " -referer \"MyComputer\" " .
                '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                '-reconnect_on_http_error 5xx,4xx,509 -reconnect_streamed 1 ' .
                '-reconnect_delay_max 2 -noautorotate ';

            $cmd .= $userArgs; // User-defined global args from config/proxy.php or QSV additional args
            $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';
            $cmd .= $videoFilterArgs; // e.g., -vf 'scale_vaapi=format=nv12' or -vf 'vpp_qsv=format=nv12'

            $cmd .= $outputFormat . ' ';
            $cmd .= '-vsync cfr '; // Add the vsync flag here
        } else {
            // Custom command template is provided
            $cmd = $customCommandTemplate;

            // Prepare placeholder values
            $hwaccelInitArgsValue = '';
            $hwaccelArgsValue = '';
            $videoFilterArgsValue = '';

            // QSV options
            $qsvEncoderOptionsValue = $settings['ffmpeg_qsv_encoder_options'] ? escapeshellarg($settings['ffmpeg_qsv_encoder_options']) : '';
            $qsvAdditionalArgsValue = $settings['ffmpeg_qsv_additional_args'] ? escapeshellarg($settings['ffmpeg_qsv_additional_args']) : '';

            // Determine codec type
            $isVaapiCodec = str_contains($finalVideoCodec, '_vaapi');
            $isQsvCodec = str_contains($finalVideoCodec, '_qsv');

            if ($settings['ffmpeg_vaapi_enabled'] ?? false) {
                $finalVideoCodec = $isVaapiCodec ? $finalVideoCodec : 'h264_vaapi'; // Default to h264_vaapi if not already set
                if (!empty($settings['ffmpeg_vaapi_device'])) {
                    $hwaccelInitArgsValue = "-init_hw_device vaapi=va_device:" . escapeshellarg($settings['ffmpeg_vaapi_device']) . " -filter_hw_device va_device ";
                    $hwaccelArgsValue = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";
                }
                if (!empty($settings['ffmpeg_vaapi_video_filter'])) {
                    $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_vaapi_video_filter'], "'\",")) . " ";
                }
            } else if ($settings['ffmpeg_qsv_enabled'] ?? false) {
                $finalVideoCodec = $isQsvCodec ? $finalVideoCodec : 'h264_qsv'; // Default to h264_qsv if not already set
                if (!empty($settings['ffmpeg_qsv_device'])) {
                    $hwaccelInitArgsValue = "-init_hw_device qsv=qsv_hw:" . escapeshellarg($settings['ffmpeg_qsv_device']) . " ";
                    $hwaccelArgsValue = '-hwaccel qsv -hwaccel_device qsv_hw -hwaccel_output_format qsv ';
                }
                if (!empty($settings['ffmpeg_qsv_video_filter'])) {
                    $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_qsv_video_filter'], "'\",")) . " ";
                }

                // Additional QSV specific options
                $codecSpecificArgs = $settings['ffmpeg_qsv_encoder_options'] ? escapeshellarg($settings['ffmpeg_qsv_encoder_options']) : '';
                if (!empty($settings['ffmpeg_qsv_additional_args'])) {
                    $userArgs = trim($settings['ffmpeg_qsv_additional_args']) . ($userArgs ? " " . $userArgs : "");
                }
            }

            $videoCodecForTemplate = $settings['ffmpeg_codec_video'] ?: 'copy';
            $audioCodecForTemplate = $settings['ffmpeg_codec_audio'] ?: 'copy';
            $subtitleCodecForTemplate = $settings['ffmpeg_codec_subtitles'] ?: 'copy';
            $audioParamsForTemplate = '';

            if ($audioCodecForTemplate === 'opus') {
                $audioCodecForTemplate = 'libopus';
                Log::channel('ffmpeg')->debug("HLS: Switched audio codec (template) from 'opus' to 'libopus'.");
            }

            if ($audioCodecForTemplate === 'libopus' && $audioCodecForTemplate !== 'copy') {
                $audioParamsForTemplate = ' -vbr 1 -b:a 128k'; // Ensure -vbr 1 comes before -b:a
                Log::channel('ffmpeg')->debug("HLS: Setting default VBR and bitrate (template) for libopus: 1, 128k.");
                // If QSV is enabled and we're using libopus, ensure QSV_ENCODER_OPTIONS doesn't add -global_quality
                if ($settings['ffmpeg_qsv_enabled'] ?? false) {
                    if (empty($settings['ffmpeg_qsv_encoder_options'])) { // Only override if user hasn't set their own
                        $qsvEncoderOptionsValue = '-preset medium'; // Remove global_quality
                    }
                }
            } elseif (($audioCodecForTemplate === 'vorbis' || $audioCodecForTemplate === 'libvorbis') && $audioCodecForTemplate !== 'copy') {
                $audioParamsForTemplate = ' -strict -2';
                Log::channel('ffmpeg')->debug("HLS: Setting -strict -2 (template) for vorbis.");
            }

            $outputCommandSegment = "-c:v {$outputVideoCodec} " .
                ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "") .
                "-c:a {$audioCodecForTemplate}{$audioParamsForTemplate} -c:s {$subtitleCodecForTemplate}";

            $videoCodecArgs = "-c:v {$videoCodecForTemplate}" . ($codecSpecificArgs ? " " . trim($codecSpecificArgs) : "");
            $audioCodecArgs = "-c:a {$audioCodecForTemplate}{$audioParamsForTemplate}";
            $subtitleCodecArgs = "-c:s {$subtitleCodecForTemplate}";

            // Perform replacements
            $cmd = str_replace('{FFMPEG_PATH}', escapeshellcmd($ffmpegPath), $cmd);
            $cmd = str_replace('{INPUT_URL}', escapeshellarg($streamUrl), $cmd);
            $cmd = str_replace('{OUTPUT_OPTIONS}', $outputCommandSegment, $cmd);
            $cmd = str_replace('{USER_AGENT}', $userAgent, $cmd); // $userAgent is already escaped
            $cmd = str_replace('{REFERER}', escapeshellarg("MyComputer"), $cmd);
            $cmd = str_replace('{HWACCEL_INIT_ARGS}', $hwaccelInitArgsValue, $cmd);
            $cmd = str_replace('{HWACCEL_ARGS}', $hwaccelArgsValue, $cmd);
            $cmd = str_replace('{VIDEO_FILTER_ARGS}', $videoFilterArgsValue, $cmd);
            $cmd = str_replace('{VIDEO_CODEC_ARGS}', $videoCodecArgs, $cmd);
            $cmd = str_replace('{AUDIO_CODEC_ARGS}', $audioCodecArgs, $cmd);
            $cmd = str_replace('{SUBTITLE_CODEC_ARGS}', $subtitleCodecArgs, $cmd);
            $cmd = str_replace('{QSV_ENCODER_OPTIONS}', $qsvEncoderOptionsValue, $cmd);
            $cmd = str_replace('{QSV_ADDITIONAL_ARGS}', $qsvAdditionalArgsValue, $cmd);
            $cmd = str_replace('{ADDITIONAL_ARGS}', $userArgs, $cmd); // If user wants to include general additional args
        }

        // Get HLS time from settings or use default
        $hlsTime = $settings['ffmpeg_hls_time'] ?? 4;
        $hlsListSize = 15; // Kept as a variable for future configurability

        // ... rest of the options and command suffix ...
        $cmd .= " -f hls -hls_time {$hlsTime} -hls_list_size {$hlsListSize} " .
            '-hls_flags delete_segments+append_list+split_by_time ' .
            '-use_wallclock_as_timestamps 1 -start_number 0 ' .
            '-hls_allow_cache 0 -hls_segment_type mpegts ' .
            '-hls_segment_filename ' . escapeshellarg($segment) . ' ' .
            '-hls_base_url ' . escapeshellarg($segmentBaseUrl) . ' ' .
            escapeshellarg($m3uPlaylist) . ' ';

        $cmd .= ($settings['ffmpeg_debug'] ? ' -loglevel verbose' : ' -hide_banner -nostats -loglevel error');

        return $cmd;
    }

    /**
     * Build the FFmpeg command for TS streaming.
     *
     * @param string $format
     * @param string $streamUrl
     * @param string $userAgent
     * @return string
     */
    public static function buildTsCommand(
        $format,
        $streamUrl,
        $userAgent
    ): string {
        // Get default stream settings
        $settings = self::getStreamSettings();
        $customCommandTemplate = $settings['ffmpeg_custom_command_template'] ?? null;

        // Get user defined options
        $userArgs = config('proxy.ffmpeg_additional_args', '');
        if (!empty($userArgs)) {
            $userArgs .= ' ';
        }

        // Get ffmpeg path
        $ffmpegPath = config('proxy.ffmpeg_path') ?: $settings['ffmpeg_path'];
        if (empty($ffmpegPath)) {
            $ffmpegPath = 'jellyfin-ffmpeg';
        }

        // Determine if it's an MKV file by extension
        $isMkv = stripos($streamUrl, '.mkv') !== false;
        $isMp4 = stripos($streamUrl, '.mp4') !== false;

        // Determine the effective video codec based on config and settings
        $videoCodec = self::determineVideoCodec(
            config('proxy.ffmpeg_codec_video', null),
            $settings['ffmpeg_codec_video'] ?? 'copy' // Default to 'copy' if not set
        );

        // Command construction logic
        if (empty($customCommandTemplate)) {
            // Initialize FFmpeg command argument variables
            $hwaccelInitArgs = '';
            $hwaccelArgs = '';
            $videoFilterArgs = '';
            $codecSpecificArgs = ''; // For QSV or other codec-specific args not part of -vf

            // Get base ffmpeg output codec formats (these are defaults or from non-QSV/VA-API settings)
            // Get base ffmpeg output codec formats (these are defaults or from non-QSV/VA-API settings)
            $audioCodec = (config('proxy.ffmpeg_codec_audio') ?: ($settings['ffmpeg_codec_audio'] ?? null)) ?: 'copy';
            $audioBitrateArgs = '';
            if ($audioCodec === 'opus') {
                $audioCodec = 'libopus';
                Log::channel('ffmpeg')->debug("Switched audio codec from 'opus' to 'libopus'.");
            }
            if ($audioCodec === 'libopus') {
                // libopus requires a bitrate or it will fail if not in a specific VBR quality mode.
                // Default to 128k if no other audio bitrate is implicitly set via global options.
                $audioBitrateArgs = '-b:a 128k -vbr on '; // Added -vbr on
                Log::channel('ffmpeg')->debug("Setting default bitrate and VBR for libopus: 128k, on.");
            }
            $subtitleCodec = (config('proxy.ffmpeg_codec_subtitles') ?: ($settings['ffmpeg_codec_subtitles'] ?? null)) ?: 'copy';

            // Hardware Acceleration Logic
            if ($settings['ffmpeg_vaapi_enabled'] ?? false) {
                $videoCodec = 'h264_vaapi'; // Default VA-API H.264 encoder
                if (!empty($settings['ffmpeg_vaapi_device'])) {
                    $escapedDevice = escapeshellarg($settings['ffmpeg_vaapi_device']);
                    $hwaccelInitArgs = "-init_hw_device vaapi=va_device:{$escapedDevice} ";
                    $hwaccelArgs = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi -filter_hw_device va_device ";
                }
                if (!empty($settings['ffmpeg_vaapi_video_filter'])) {
                    $videoFilterArgs = "-vf " . escapeshellarg(trim($settings['ffmpeg_vaapi_video_filter'], "'\",")) . " ";
                } else {
                    $videoFilterArgs = "-vf 'scale_vaapi=format=nv12' ";
                }
            } else if ($settings['ffmpeg_qsv_enabled'] ?? false) {
                $videoCodec = 'h264_qsv'; // Default QSV H.264 encoder

                // Simplify QSV initialization - don't specify device path directly
                $hwaccelInitArgs = "-init_hw_device qsv=qsv_device ";
                $hwaccelArgs = "-hwaccel qsv -hwaccel_device qsv_device -hwaccel_output_format qsv -filter_hw_device qsv_device ";

                if (!empty($settings['ffmpeg_qsv_video_filter'])) {
                    $videoFilterArgs = "-vf " . escapeshellarg(trim($settings['ffmpeg_qsv_video_filter'], "'\",")) . " ";
                } else {
                    // Default QSV video filter, matches user's working example
                    $videoFilterArgs = "-vf 'hwupload=extra_hw_frames=64,scale_qsv=format=nv12' ";
                }

                // Additional QSV specific options
                if ($settings['ffmpeg_qsv_encoder_options']) {
                    $codecSpecificArgs = escapeshellarg($settings['ffmpeg_qsv_encoder_options']);
                } else {
                    // Default QSV encoder options
                    $codecSpecificArgs = '-preset medium';
                    // Only add -global_quality if NOT using libopus, as it might interfere
                    if ($audioCodec !== 'libopus') {
                        $codecSpecificArgs .= ' -global_quality 23';
                    }
                }
                if (!empty($settings['ffmpeg_qsv_additional_args'])) {
                    $userArgs = trim($settings['ffmpeg_qsv_additional_args']) . ($userArgs ? " " . $userArgs : "");
                }
            }

            // Explicitly determine audio arguments
            $audioOutputArgs = "-c:a {$audioCodec}";
            if ($audioCodec === 'libopus') {
                $opusArgs = " -vbr 1";
                // Add default bitrate for libopus if no other audio bitrate is implicitly set by other args
                // Check against $userArgs only, as $codecSpecificArgs is for video.
                if (strpos($userArgs, '-b:a') === false) {
                    $opusArgs .= " -b:a 128k";
                }
                $audioOutputArgs .= $opusArgs;
                Log::channel('ffmpeg')->debug("StreamController: Updated libopus audio arguments. Audio Args: {$audioOutputArgs}");
            } elseif ($audioCodec === 'vorbis' || $audioCodec === 'libvorbis') {
                // Add -strict -2 for vorbis encoder
                $audioOutputArgs .= " -strict -2";
                Log::channel('ffmpeg')->debug("StreamController: Added -strict -2 for vorbis. Audio Args: {$audioOutputArgs}");
            }

            // Set the output format and codecs
            if (!($isMkv || $isMp4)) {
                $output = "-c:v {$videoCodec} " . ($codecSpecificArgs ? trim($codecSpecificArgs) . " " : "") . " {$audioOutputArgs} -c:s {$subtitleCodec} ";

                // Add MPEG-TS specific options for better compatibility
                $output .= "-f mpegts -mpegts_copyts 0 -mpegts_original_network_id 1 ";
                $output .= "-mpegts_transport_stream_id 1 -mpegts_service_id 1 ";
                $output .= "-mpegts_pmt_start_pid 4096 -mpegts_start_pid 256 ";
                $output .= "-muxrate 0 -pcr_period 20 ";
                $output .= "pipe:1";
            } else {
                // For mkv/mp4 format
                $bsfArgs = '';
                if ($audioCodec === 'copy') {
                    // Check if the source audio is AAC, as the filter is specific to AAC ADTS
                    // This is a heuristic. A more robust check would involve ffprobe output if available here.
                    // For now, we apply it if copying audio to mp4, as it's a common scenario for this issue.
                    $bsfArgs = '-bsf:a aac_adtstoasc ';
                    Log::channel('ffmpeg')->debug("Adding aac_adtstoasc bitstream filter for mp4 output with audio copy.");
                }
                $output = "-c:v {$videoCodec} -ac 2 {$audioOutputArgs} {$bsfArgs}-f mp4 -movflags frag_keyframe+empty_moov+default_base_moof pipe:1";
            }

            // Note: The previous complex ternary for mp4 audio was simplified as $qsvAudioArguments now correctly forms the full audio part.
            // If $audioCodec was 'copy', $qsvAudioArguments is just '-c:a copy' and no bitrate is added.

            // Build the FFmpeg command
            $cmd = escapeshellcmd($ffmpegPath) . ' ';
            $cmd .= $hwaccelInitArgs;
            $cmd .= $hwaccelArgs;

            // Input stream analysis and buffer handling
            $cmd .= '-fflags nobuffer+igndts -flags low_delay -avoid_negative_ts make_zero ';
            $cmd .= '-analyzeduration 1M -probesize 1M -max_delay 200000 ';

            // Better error handling and stream format detection
            $cmd .= '-err_detect ignore_err -ignore_unknown -fflags +discardcorrupt ';
            $cmd .= '-thread_queue_size 256 ';

            // Pre-input HTTP options:
            $cmd .= "-user_agent " . escapeshellarg($userAgent) . " -referer " . escapeshellarg("MyComputer") . " " .
                '-multiple_requests 1 -reconnect_on_network_error 1 ' .
                '-reconnect_on_http_error 5xx,4xx -reconnect_streamed 1 ' .
                '-reconnect_delay_max 2 ';

            // Add rw_timeout for all http/https inputs to make ffmpeg fail faster on stall
            if (preg_match('/^https?:\/\//', $streamUrl)) {
                $cmd .= '-rw_timeout 10000000 '; // 10 seconds in microseconds
            }

            if ($isMkv) {
                $cmd .= ' -analyzeduration 10M -probesize 10M ';
            }

            // Add stream copy options to preserve timing
            $cmd .= ' -copyts -start_at_zero -noautorotate ';

            // User defined general options:
            $cmd .= $userArgs;

            // Input:
            $cmd .= '-i ' . escapeshellarg($streamUrl) . ' ';

            // Video Filter arguments:
            $cmd .= $videoFilterArgs;

            // Output options from $output variable:
            $cmd .= $output;
        } else {
            // Custom command template is provided
            $cmd = $customCommandTemplate;

            // Prepare placeholder values
            $hwaccelInitArgsValue = '';
            $hwaccelArgsValue = '';
            $videoFilterArgsValue = '';

            // QSV options
            $qsvEncoderOptionsValue = $settings['ffmpeg_qsv_encoder_options'] ? escapeshellarg($settings['ffmpeg_qsv_encoder_options']) : '';
            $qsvAdditionalArgsValue = $settings['ffmpeg_qsv_additional_args'] ? escapeshellarg($settings['ffmpeg_qsv_additional_args']) : '';

            // Determine codec type
            $isVaapiCodec = str_contains($videoCodec, '_vaapi');
            $isQsvCodec = str_contains($videoCodec, '_qsv');

            if ($settings['ffmpeg_vaapi_enabled'] ?? false) {
                $videoCodec = $isVaapiCodec ? $videoCodec : 'h264_vaapi'; // Default to h264_vaapi if not already set
                if (!empty($settings['ffmpeg_vaapi_device'])) {
                    $hwaccelInitArgsValue = "-init_hw_device vaapi=va_device:" . escapeshellarg($settings['ffmpeg_vaapi_device']) . " -filter_hw_device va_device ";
                    $hwaccelArgsValue = "-hwaccel vaapi -hwaccel_device va_device -hwaccel_output_format vaapi ";
                }
                if (!empty($settings['ffmpeg_vaapi_video_filter'])) {
                    $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_vaapi_video_filter'], "'\",")) . " ";
                }
            } else if ($settings['ffmpeg_qsv_enabled'] ?? false) {
                $videoCodec = $isQsvCodec ? $videoCodec : 'h264_qsv'; // Default to h264_qsv if not already set
                if (!empty($settings['ffmpeg_qsv_device'])) {
                    $hwaccelInitArgsValue = "-init_hw_device qsv=qsv_hw:" . escapeshellarg($settings['ffmpeg_qsv_device']) . " ";
                    $hwaccelArgsValue = '-hwaccel qsv -hwaccel_device qsv_hw -hwaccel_output_format qsv ';
                }
                if (!empty($settings['ffmpeg_qsv_video_filter'])) {
                    $videoFilterArgsValue = "-vf " . escapeshellarg(trim($settings['ffmpeg_qsv_video_filter'], "'\",")) . " ";
                }

                // Additional QSV specific options
                $codecSpecificArgs = $settings['ffmpeg_qsv_encoder_options'] ? escapeshellarg($settings['ffmpeg_qsv_encoder_options']) : '';
                if (!empty($settings['ffmpeg_qsv_additional_args'])) {
                    $userArgs = trim($settings['ffmpeg_qsv_additional_args']) . ($userArgs ? " " . $userArgs : "");
                }
            }

            $videoCodecForTemplate = $settings['ffmpeg_codec_video'] ?: 'copy';
            $audioCodecForTemplate = (config('proxy.ffmpeg_codec_audio') ?: ($settings['ffmpeg_codec_audio'] ?? null)) ?: 'copy';
            $audioParamsForTemplate = '';
            if ($audioCodecForTemplate === 'opus') {
                $audioCodecForTemplate = 'libopus';
                Log::channel('ffmpeg')->debug("Switched audio codec (template) from 'opus' to 'libopus'.");
            }

            if ($audioCodecForTemplate === 'libopus' && $audioCodecForTemplate !== 'copy') {
                // Add default VBR and bitrate for libopus if not copying, ensuring -vbr 1 comes first
                $audioParamsForTemplate = ' -vbr 1 -b:a 128k';
                Log::channel('ffmpeg')->debug("Setting default VBR and bitrate (template) for libopus: 1, 128k.");
                // If QSV is enabled and we're using libopus, ensure QSV_ENCODER_OPTIONS doesn't add -global_quality
                if ($settings['ffmpeg_qsv_enabled'] ?? false) {
                    if (empty($settings['ffmpeg_qsv_encoder_options'])) { // Only override if user hasn't set their own
                        $qsvEncoderOptionsValue = '-preset medium'; // Remove global_quality
                    }
                }
            } elseif (($audioCodecForTemplate === 'vorbis' || $audioCodecForTemplate === 'libvorbis') && $audioCodecForTemplate !== 'copy') {
                // Add -strict -2 for vorbis encoder
                $audioParamsForTemplate = ' -strict -2';
                Log::channel('ffmpeg')->debug("Setting -strict -2 (template) for vorbis.");
            }
            $subtitleCodecForTemplate = (config('proxy.ffmpeg_codec_subtitles') ?: ($settings['ffmpeg_codec_subtitles'] ?? null)) ?: 'copy';

            // Construct audio codec arguments including bitrate if applicable
            $audioCodecArgs = "-c:a {$audioCodecForTemplate}{$audioParamsForTemplate}";

            $outputCommandSegment = $format === 'ts'
                ? "-c:v {$videoCodecForTemplate} {$audioCodecArgs} -c:s {$subtitleCodecForTemplate} -f mpegts pipe:1"
                : "-c:v {$videoCodecForTemplate} -ac 2 {$audioCodecArgs} -f mp4 -movflags frag_keyframe+empty_moov+default_base_moof pipe:1";

            // For the template, we assume {OUTPUT_OPTIONS} or specific codec args will handle this.
            // The individual {AUDIO_CODEC_ARGS} should now include the bitrate.
            $videoCodecArgs = "-c:v {$videoCodecForTemplate}";
            // $audioCodecArgs is already constructed above
            $subtitleCodecArgs = "-c:s {$subtitleCodecForTemplate}";

            // Perform replacements
            $cmd = str_replace('{FFMPEG_PATH}', escapeshellcmd($ffmpegPath), $cmd);
            $cmd = str_replace('{INPUT_URL}', escapeshellarg($streamUrl), $cmd);
            $cmd = str_replace('{OUTPUT_OPTIONS}', $outputCommandSegment, $cmd);
            $cmd = str_replace('{USER_AGENT}', $userAgent, $cmd); // $userAgent is already escaped
            $cmd = str_replace('{REFERER}', escapeshellarg("MyComputer"), $cmd);
            $cmd = str_replace('{HWACCEL_INIT_ARGS}', $hwaccelInitArgsValue, $cmd);
            $cmd = str_replace('{HWACCEL_ARGS}', $hwaccelArgsValue, $cmd);
            $cmd = str_replace('{VIDEO_FILTER_ARGS}', $videoFilterArgsValue, $cmd);
            $cmd = str_replace('{VIDEO_CODEC_ARGS}', $videoCodecArgs, $cmd);
            $cmd = str_replace('{AUDIO_CODEC_ARGS}', $audioCodecArgs, $cmd);
            $cmd = str_replace('{SUBTITLE_CODEC_ARGS}', $subtitleCodecArgs, $cmd);
            $cmd = str_replace('{QSV_ENCODER_OPTIONS}', $qsvEncoderOptionsValue, $cmd);
            $cmd = str_replace('{QSV_ADDITIONAL_ARGS}', $qsvAdditionalArgsValue, $cmd);
            $cmd = str_replace('{ADDITIONAL_ARGS}', $userArgs, $cmd); // If user wants to include general additional args
        }

        // ... rest of the options and command suffix ...
        $cmd .= ($settings['ffmpeg_debug'] ? ' -loglevel verbose' : ' -hide_banner -loglevel error');

        return $cmd;
    }
}
