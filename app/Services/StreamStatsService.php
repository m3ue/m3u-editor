<?php

namespace App\Services;

use Illuminate\Support\Str;

class StreamStatsService
{
    /**
     * Normalise raw stream stats into a unified flat array.
     *
     * Supports both flat stats (keys like resolution, video_codec, etc.)
     * and nested stream arrays (e.g. from ffprobe with codec_type keys).
     *
     * @param  array<string, mixed>|null  $streamStats
     * @return array<string, mixed>
     */
    public static function normalize(?array $streamStats): array
    {
        $stats = is_array($streamStats) ? $streamStats : [];

        if (isset($stats['resolution']) || isset($stats['video_codec']) || isset($stats['audio_codec']) || isset($stats['hdr']) || isset($stats['video_profile']) || isset($stats['color_transfer'])) {
            return $stats;
        }

        $video = null;
        $audio = null;

        foreach ($stats as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $stream = $entry['stream'] ?? $entry;
            if (! is_array($stream)) {
                continue;
            }

            if (($stream['codec_type'] ?? null) === 'video' && $video === null) {
                $video = $stream;
            }

            if (($stream['codec_type'] ?? null) === 'audio' && $audio === null) {
                $audio = $stream;
            }
        }

        return [
            'resolution' => ($video['width'] ?? null) && ($video['height'] ?? null)
                ? $video['width'].'x'.$video['height']
                : null,
            'width' => $video['width'] ?? null,
            'height' => $video['height'] ?? null,
            'video_codec' => $video['codec_name'] ?? null,
            'video_profile' => $video['profile'] ?? null,
            'hdr' => $video['hdr'] ?? null,
            'color_transfer' => $video['color_transfer'] ?? null,
            'color_space' => $video['color_space'] ?? null,
            'color_primaries' => $video['color_primaries'] ?? null,
            'color_range' => $video['color_range'] ?? null,
            'pix_fmt' => $video['pix_fmt'] ?? null,
            'codec_tag_string' => $video['codec_tag_string'] ?? null,
            'side_data_list' => $video['side_data_list'] ?? null,
            'bit_depth' => self::extractBitDepth($video),
            'audio_codec' => $audio['codec_name'] ?? null,
            'audio_profile' => $audio['profile'] ?? null,
            'audio_channels' => $audio['channels'] ?? $audio['channel_layout'] ?? null,
        ];
    }

    /**
     * Try to derive bit depth from a video stream entry.
     *
     * @param  array<string, mixed>|null  $video
     */
    private static function extractBitDepth(?array $video): ?int
    {
        if (! is_array($video)) {
            return null;
        }

        $bps = $video['bits_per_raw_sample'] ?? null;
        if (is_numeric($bps) && (int) $bps > 0) {
            return (int) $bps;
        }

        $pix = strtolower((string) ($video['pix_fmt'] ?? ''));
        if ($pix !== '' && preg_match('/(\d{1,2})(?:le|be)?p?$/', $pix, $m) === 1) {
            $depth = (int) $m[1];
            if ($depth >= 8 && $depth <= 16) {
                return $depth;
            }
        }

        return null;
    }

    /**
     * Detect video quality string (e.g. "1080p", "720p", "4K") from normalized stats.
     *
     * @param  array<string, mixed>  $stats
     */
    public static function detectQuality(array $stats): string
    {
        $resolution = self::stringValue($stats['resolution'] ?? null);
        $height = null;
        $width = null;

        if ($resolution !== '') {
            if (preg_match('/(?<width>\d{3,5})\s*[x×]\s*(?<height>\d{3,5})/i', $resolution, $matches) === 1) {
                $width = (int) $matches['width'];
                $height = (int) $matches['height'];
            } elseif (preg_match('/(?<height>2160|1440|1080|720|480|576)p?/i', $resolution, $matches) === 1) {
                $height = (int) $matches['height'];
            } elseif (Str::of($resolution)->lower()->contains('4k')) {
                return '4K';
            }
        }

        $width ??= self::intValue($stats['width'] ?? null);
        $height ??= self::intValue($stats['height'] ?? null);

        if (($width !== null && $width >= 3800) || ($height !== null && $height >= 2000)) {
            return '4K';
        }

        if ($height !== null && $height >= 1080) {
            return '1080p';
        }

        if ($height !== null && $height >= 720) {
            return '720p';
        }

        return $height !== null && $height > 0 ? $height.'p' : '';
    }

    /**
     * Detect audio format string (e.g. "AAC 5.1", "DTS") from normalized stats.
     *
     * @param  array<string, mixed>  $stats
     */
    public static function detectAudio(array $stats): string
    {
        $codec = self::formatAudioCodec(self::stringValue($stats['audio_codec'] ?? null));
        $channels = self::formatAudioChannels($stats['audio_channels'] ?? $stats['channels'] ?? null);

        return trim(implode(' ', array_filter([$codec, $channels])));
    }

    /**
     * Detect video codec display string (e.g. "H.264", "H.265") from normalized stats.
     *
     * @param  array<string, mixed>  $stats
     */
    public static function detectVideoCodec(array $stats): string
    {
        $raw = self::stringValue($stats['video_codec'] ?? $stats['codec_name'] ?? null);

        return match (Str::of($raw)->lower()->replace(['_', '-'], ' ')->squish()->value()) {
            'h264', 'h 264', 'avc', 'mpeg4 avc' => 'H.264',
            'h265', 'h 265', 'hevc' => 'H.265',
            'av1' => 'AV1',
            'vp9' => 'VP9',
            'vp8' => 'VP8',
            'mpeg2video', 'mpeg 2 video', 'mpeg2' => 'MPEG-2',
            default => Str::of($raw)->upper()->value(),
        };
    }

    /**
     * Detect HDR format string (e.g. "DV", "HDR10+", "HDR") from normalized stats.
     *
     * @param  array<string, mixed>  $stats
     */
    public static function detectHdr(array $stats): string
    {
        $hdr = Str::of(self::stringValue($stats['hdr'] ?? $stats['dynamic_range'] ?? null))->lower();
        $profile = Str::of(self::stringValue($stats['video_profile'] ?? $stats['profile'] ?? null))->lower();
        $colorTransfer = Str::of(self::stringValue($stats['color_transfer'] ?? null))->lower();
        $colorSpace = Str::of(self::stringValue($stats['color_space'] ?? null))->lower();
        $colorPrimaries = Str::of(self::stringValue($stats['color_primaries'] ?? null))->lower();
        $codecTag = Str::of(self::stringValue($stats['codec_tag_string'] ?? null))->lower();

        // ffprobe encodes HDR side data (mastering display, content light level,
        // dynamic metadata, Dolby Vision configuration) as a list under side_data_list.
        $sideDataDump = '';
        $sideData = $stats['side_data_list'] ?? null;
        if (is_array($sideData)) {
            $flat = [];
            array_walk_recursive($sideData, static function ($value) use (&$flat) {
                if (is_scalar($value)) {
                    $flat[] = (string) $value;
                }
            });
            $sideDataDump = strtolower(implode(' ', $flat));
        }

        $combined = Str::of($hdr.' '.$profile.' '.$colorTransfer.' '.$colorSpace.' '.$colorPrimaries.' '.$codecTag.' '.$sideDataDump);

        // Dolby Vision: dvh1/dvhe codec tag, profile string, or DOVI configuration record.
        if (
            $combined->contains(['dolby vision', 'dovi', 'dvhe', 'dvh1', 'dolby_vision', 'dovi configuration'])
            || $codecTag->is(['dvh1', 'dvhe', 'dav1', 'dva1'])
        ) {
            return 'DV';
        }

        // HDR10+: SMPTE2094-40 dynamic metadata.
        if ($combined->contains(['hdr10+', 'hdr10plus', 'smpte2094-40', 'smpte 2094-40', 'smpte2094_40', 'st2094-40'])) {
            return 'HDR10+';
        }

        // HDR10: PQ transfer (smpte2084) plus mastering metadata. We emit "HDR10"
        // explicitly when the mastering display / content light level side data is
        // present, otherwise fall back to generic "HDR".
        $hasPq = $colorTransfer->contains(['smpte2084', 'st2084']);
        $hasMastering = str_contains($sideDataDump, 'mastering display')
            || str_contains($sideDataDump, 'content light level');

        if ($hasPq && $hasMastering) {
            return 'HDR10';
        }

        if ($combined->contains(['hdr10'])) {
            return 'HDR10';
        }

        if ($combined->contains(['hdr', 'smpte2084', 'st2084', 'arib-std-b67', 'hlg', 'bt2020'])) {
            return 'HDR';
        }

        return '';
    }

    /**
     * Format an audio codec string into a display name.
     */
    public static function formatAudioCodec(string $codec): string
    {
        return match (Str::of($codec)->lower()->replace(['_', '-'], ' ')->squish()->value()) {
            'aac' => 'AAC',
            'ac3' => 'AC-3',
            'eac3', 'e ac3', 'e ac 3' => 'E-AC-3',
            'dts' => 'DTS',
            'truehd' => 'TrueHD',
            'flac' => 'FLAC',
            'mp3' => 'MP3',
            'opus' => 'Opus',
            default => $codec !== '' ? Str::of($codec)->upper()->value() : '',
        };
    }

    /**
     * Format an audio channel count/layout into a display string.
     */
    public static function formatAudioChannels(mixed $channels): string
    {
        $value = Str::of(self::stringValue($channels))->lower()->replace([' ', '_'], '')->value();

        return match ($value) {
            '1', 'mono' => '1.0',
            '2', 'stereo' => '2.0',
            '6', '5.1', '5-1' => '5.1',
            '8', '7.1', '7-1' => '7.1',
            default => self::stringValue($channels),
        };
    }

    private static function stringValue(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private static function intValue(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }
}
