<?php

namespace App\Services;

use App\DataObjects\ClientCapabilities;
use App\Models\StreamProfile;

class PlaybackCapabilityService
{
    private const FORMAT_NAME_ALIASES = [
        'mpegts' => 'mpegts',
        'mpegtsraw' => 'mpegts',
        'ts' => 'mpegts',
        'mpeg' => 'mpegts',
        'hls' => 'hls',
        'm3u8' => 'hls',
        'dash' => 'dash',
        'mp4' => 'mp4',
        'mov' => 'mp4',
        'matroska' => 'mkv',
        'mkv' => 'mkv',
        'flv' => 'flv',
        'avi' => 'avi',
    ];

    private const VIDEO_ENCODER_ALIASES = [
        'libx264' => 'h264',
        'h264' => 'h264',
        'h264_nvenc' => 'h264',
        'h264_qsv' => 'h264',
        'h264_vaapi' => 'h264',
        'libx265' => 'hevc',
        'hevc' => 'hevc',
        'hevc_nvenc' => 'hevc',
        'hevc_qsv' => 'hevc',
        'hevc_vaapi' => 'hevc',
        'libaom-av1' => 'av1',
        'libsvtav1' => 'av1',
        'av1' => 'av1',
        'libvpx-vp9' => 'vp9',
        'vp9' => 'vp9',
    ];

    private const AUDIO_ENCODER_ALIASES = [
        'aac' => 'aac',
        'libfdk_aac' => 'aac',
        'libmp3lame' => 'mp3',
        'mp3' => 'mp3',
        'ac3' => 'ac3',
        'eac3' => 'eac3',
        'libopus' => 'opus',
        'opus' => 'opus',
        'flac' => 'flac',
    ];

    public static function decide(
        ClientCapabilities $client,
        ?array $streamStats,
        bool $canTranscode = false,
        ?array $transcodeOutput = null,
    ): array {
        $stats = StreamStatsService::normalize($streamStats ?? []);

        $sourceVideoCodec = self::normalizeCodec($stats['video_codec'] ?? '');
        $sourceAudioCodec = strtolower((string) ($stats['audio_codec'] ?? ''));
        $sourceHeight = is_numeric($stats['height'] ?? null) ? (int) $stats['height'] : null;
        $sourceWidth = is_numeric($stats['width'] ?? null) ? (int) $stats['width'] : null;
        $sourceBitrate = is_numeric($stats['bit_rate'] ?? null) ? (int) $stats['bit_rate'] : null;
        $sourceHdr = self::detectHdrState($stats);

        $reasons = self::incompatibilityReasons(
            $client,
            $sourceVideoCodec,
            $sourceAudioCodec,
            self::detectContainer($streamStats),
            $sourceHeight,
            $sourceBitrate === null ? null : (int) round($sourceBitrate / 1000),
            $sourceHdr,
        );

        $source = [
            'video_codec' => $stats['video_codec'] ?? null,
            'audio_codec' => $stats['audio_codec'] ?? null,
            'container' => self::detectContainer($streamStats),
            'width' => $sourceWidth,
            'height' => $sourceHeight,
            'bitrate_kbps' => $sourceBitrate === null ? null : (int) round($sourceBitrate / 1000),
            'hdr' => $sourceHdr,
        ];

        if (empty($reasons)) {
            return [
                'mode' => 'direct_play',
                'reason' => 'Client supports all stream codecs and container',
                'source' => $source,
            ];
        }

        $reason = implode('; ', $reasons);

        if ($canTranscode && $transcodeOutput !== null && self::outputIsCompatible(
            $client,
            $transcodeOutput,
            $sourceHeight,
            $sourceBitrate === null ? null : (int) round($sourceBitrate / 1000),
            $sourceHdr,
        )) {
            return [
                'mode' => 'transcode',
                'reason' => $reason,
                'source' => $source,
                'output' => $transcodeOutput,
            ];
        }

        return [
            'mode' => 'unsupported',
            'reason' => $reason.' and no compatible transcode profile available',
            'source' => $source,
        ];
    }

    public static function inspectTranscodeOutput(?StreamProfile $profile): ?array
    {
        if (! $profile || $profile->backend !== 'ffmpeg') {
            return null;
        }

        $args = (string) $profile->args;
        $videoEncoder = self::extractOption($args, ['-c:v', '-codec:v', '-vcodec']);
        $audioEncoder = self::extractOption($args, ['-c:a', '-codec:a', '-acodec']);
        $format = self::extractOption($args, ['-f']) ?? (string) $profile->format;

        $videoCodec = self::VIDEO_ENCODER_ALIASES[strtolower((string) $videoEncoder)] ?? null;
        $audioCodec = self::AUDIO_ENCODER_ALIASES[strtolower((string) $audioEncoder)] ?? null;
        $container = ClientCapabilities::normalizeContainer($format);

        if ($videoCodec === null || $audioCodec === null || $container === '') {
            return null;
        }

        return [
            'video_codec' => $videoCodec,
            'audio_codec' => $audioCodec,
            'container' => $container !== '' ? $container : null,
            'max_height' => self::extractMaxHeight($args),
            'max_bitrate_kbps' => self::extractBitrateKbps($args),
            'hdr' => self::inferSdrOutput($args),
        ];
    }

    public static function detectContainer(?array $streamStats): ?string
    {
        if (! is_array($streamStats)) {
            return null;
        }

        foreach ($streamStats as $entry) {
            if (isset($entry['format']['format_name'])) {
                $names = explode(',', (string) $entry['format']['format_name']);
                $primary = strtolower(trim($names[0]));

                return self::FORMAT_NAME_ALIASES[$primary] ?? $primary;
            }
        }

        return null;
    }

    public static function detectHdrState(array $stats): ?bool
    {
        if (StreamStatsService::detectHdr($stats) !== '') {
            return true;
        }

        $hdr = $stats['hdr'] ?? null;
        if (is_bool($hdr)) {
            return $hdr;
        }

        if (is_numeric($hdr)) {
            return (bool) $hdr;
        }

        $normalizedHdr = strtolower(trim((string) $hdr));
        if (in_array($normalizedHdr, ['sdr', 'false', 'no', 'none'], true)) {
            return false;
        }

        $colorTransfer = strtolower(trim((string) ($stats['color_transfer'] ?? '')));
        if ($colorTransfer !== '' && ! in_array($colorTransfer, ['unknown', 'unspecified', 'reserved'], true)) {
            return false;
        }

        return null;
    }

    private static function incompatibilityReasons(
        ClientCapabilities $client,
        ?string $videoCodec,
        ?string $audioCodec,
        ?string $container,
        ?int $height,
        ?int $bitrateKbps,
        ?bool $hdr,
    ): array {
        $reasons = [];

        if (! empty($client->videoCodecs) && ($videoCodec === null || $videoCodec === '')) {
            $reasons[] = 'Source video codec is unknown';
        }

        if (! empty($client->videoCodecs) && $videoCodec !== null && $videoCodec !== ''
            && ! in_array(self::normalizeCodec($videoCodec), $client->videoCodecs, true)) {
            $reasons[] = "Video codec '{$videoCodec}' not supported by client";
        }

        if (! empty($client->audioCodecs) && ($audioCodec === null || $audioCodec === '')) {
            $reasons[] = 'Source audio codec is unknown';
        }

        if (! empty($client->audioCodecs) && $audioCodec !== null && $audioCodec !== ''
            && ! in_array(strtolower($audioCodec), $client->audioCodecs, true)) {
            $reasons[] = "Audio codec '{$audioCodec}' not supported by client";
        }

        if (! empty($client->containers) && ($container === null || $container === '')) {
            $reasons[] = 'Source container is unknown';
        }

        if ($container !== null && ! empty($client->containers)
            && ! in_array(ClientCapabilities::normalizeContainer($container), $client->containers, true)) {
            $reasons[] = "Container '{$container}' not supported by client";
        }

        if ($client->maxHeight !== null) {
            if ($height === null) {
                $reasons[] = 'Source height is unknown';
            } elseif ($height > $client->maxHeight) {
                $reasons[] = "Source height {$height}px exceeds client max {$client->maxHeight}px";
            }
        }

        if ($client->maxBitrateKbps !== null) {
            if ($bitrateKbps === null) {
                $reasons[] = 'Source bitrate is unknown';
            } elseif ($bitrateKbps > $client->maxBitrateKbps) {
                $reasons[] = "Source bitrate {$bitrateKbps}kbps exceeds client max {$client->maxBitrateKbps}kbps";
            }
        }

        if ($client->hdr === false) {
            if ($hdr === null) {
                $reasons[] = 'Source HDR status is unknown';
            } elseif ($hdr === true) {
                $reasons[] = 'Source is HDR but client does not support HDR';
            }
        }

        return $reasons;
    }

    private static function outputIsCompatible(
        ClientCapabilities $client,
        array $output,
        ?int $sourceHeight,
        ?int $sourceBitrateKbps,
        ?bool $sourceHdr,
    ): bool {
        $videoCodec = $output['video_codec'] ?? null;
        $audioCodec = $output['audio_codec'] ?? null;
        $container = $output['container'] ?? null;

        if (! is_string($videoCodec) || ! is_string($audioCodec) || ! is_string($container)) {
            return false;
        }

        if (! empty($client->videoCodecs)
            && ! in_array(self::normalizeCodec($videoCodec), $client->videoCodecs, true)) {
            return false;
        }

        if (! empty($client->audioCodecs)
            && ! in_array(strtolower($audioCodec), $client->audioCodecs, true)) {
            return false;
        }

        if (! empty($client->containers)
            && ! in_array(ClientCapabilities::normalizeContainer($container), $client->containers, true)) {
            return false;
        }

        if ($client->maxHeight !== null) {
            $outputHeight = $output['max_height'] ?? null;
            if (is_int($outputHeight) && $outputHeight > $client->maxHeight) {
                return false;
            }

            if (($sourceHeight === null || $sourceHeight > $client->maxHeight) && ! is_int($outputHeight)) {
                return false;
            }
        }

        if ($client->maxBitrateKbps !== null) {
            $outputBitrate = $output['max_bitrate_kbps'] ?? null;
            if (is_int($outputBitrate) && $outputBitrate > $client->maxBitrateKbps) {
                return false;
            }

            if (($sourceBitrateKbps === null || $sourceBitrateKbps > $client->maxBitrateKbps)
                && ! is_int($outputBitrate)) {
                return false;
            }
        }

        if ($client->hdr === false && $sourceHdr !== false && ($output['hdr'] ?? null) !== false) {
            return false;
        }

        return true;
    }

    private static function extractOption(string $args, array $options): ?string
    {
        $pattern = '/(?:^|\s)(?:'.implode('|', array_map(fn (string $option) => preg_quote($option, '/'), $options)).')(?:=|\s+)([^\s]+)/i';
        if (! preg_match_all($pattern, $args, $matches) || empty($matches[1])) {
            return null;
        }

        return trim((string) end($matches[1]), "\"'");
    }

    private static function extractMaxHeight(string $args): ?int
    {
        if (preg_match('/(?:^|\s)-s(?:=|\s+)(\d+)x(\d+)/i', $args, $matches)) {
            return (int) $matches[2];
        }

        if (preg_match('/scale(?:=|[^\s,]*:)[^\s,]*[x:](-?\d+)/i', $args, $matches)) {
            $height = (int) $matches[1];

            return $height > 0 ? $height : null;
        }

        return null;
    }

    private static function extractBitrateKbps(string $args): ?int
    {
        $value = self::extractOption($args, ['-maxrate:v', '-maxrate', '-b:v', '-vb']);
        if ($value === null || ! preg_match('/^(\d+(?:\.\d+)?)([kmg])?$/i', $value, $matches)) {
            return null;
        }

        $multiplier = match (strtolower($matches[2] ?? '')) {
            'm' => 1000,
            'g' => 1000000,
            default => 1,
        };

        return (int) round((float) $matches[1] * $multiplier);
    }

    private static function inferSdrOutput(string $args): ?bool
    {
        $normalized = strtolower($args);
        $hasToneMapping = str_contains($normalized, 'tonemap');
        $hasBt709Transfer = preg_match('/(?:-color_trc\s+bt709|transfer(?:=|:)bt709)/', $normalized) === 1;
        $hasBt709Primaries = preg_match('/(?:-color_primaries\s+bt709|primaries(?:=|:)bt709)/', $normalized) === 1;
        $hasBt709Matrix = preg_match('/(?:-colorspace\s+bt709|matrix(?:=|:)bt709)/', $normalized) === 1;

        return $hasToneMapping && $hasBt709Transfer && $hasBt709Primaries && $hasBt709Matrix
            ? false
            : null;
    }

    private static function normalizeCodec(string $codec): string
    {
        return ClientCapabilities::normalizeCodec($codec);
    }
}
