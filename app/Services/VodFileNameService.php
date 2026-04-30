<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\StreamFileSetting;
use Illuminate\Support\Str;

class VodFileNameService
{
    /**
     * Generate a filesystem-safe VOD movie filename from the configured format.
     */
    public function generateMovieFileName(Channel $channel, StreamFileSetting $setting): string
    {
        $format = filled($setting->movie_format ?? null)
            ? (string) $setting->movie_format
            : '{title} ({year}) {edition} [{quality} {video} {audio} {hdr}] - {group}';

        $streamStats = (bool) ($setting->use_stream_stats ?? true)
            ? $this->normalizeStreamStats($channel->stream_stats ?? [])
            : [];

        $replacements = [
            '{title}' => PlaylistService::makeFilesystemSafe($this->movieTitle($channel), $setting->replace_char ?? 'space'),
            '{year}' => $this->movieYear($channel),
            '{edition}' => $this->formatOptional($this->scalarAttribute($channel, 'edition'), prefix: ' '),
            '{quality}' => $this->quality($channel, $setting, $streamStats),
            '{audio}' => (bool) ($setting->use_stream_stats ?? true) ? $this->detectAudio($streamStats) : $this->manualValue($channel, $setting, ['audio', 'audio_format', 'audio_codec']),
            '{video}' => (bool) ($setting->use_stream_stats ?? true) ? $this->detectVideoCodec($streamStats) : $this->manualValue($channel, $setting, ['video', 'video_format', 'video_codec']),
            '{hdr}' => (bool) ($setting->use_stream_stats ?? true) ? $this->detectHdr($streamStats) : $this->manualValue($channel, $setting, ['hdr', 'hdr_format']),
            '{group}' => $this->scalarAttribute($channel, 'release_group'),
        ];

        $fileName = strtr($format, $replacements);
        $fileName = $this->cleanUnfilledPlaceholders($fileName);

        return PlaylistService::makeFilesystemSafe($fileName, $setting->replace_char ?? 'space');
    }

    /**
     * @param  array<string, mixed>  $streamStats
     */
    public function detectQuality(array $streamStats): string
    {
        $resolution = $this->stringValue($streamStats['resolution'] ?? null);
        $height = null;
        $width = null;

        if ($resolution !== '') {
            if (preg_match('/(?<width>\d{3,5})\s*[x×]\s*(?<height>\d{3,5})/i', $resolution, $matches) === 1) {
                $width = (int) $matches['width'];
                $height = (int) $matches['height'];
            } elseif (preg_match('/(?<height>2160|1080|720|480|576)p?/i', $resolution, $matches) === 1) {
                $height = (int) $matches['height'];
            } elseif (Str::of($resolution)->lower()->contains('4k')) {
                return '4K';
            }
        }

        $width ??= $this->intValue($streamStats['width'] ?? null);
        $height ??= $this->intValue($streamStats['height'] ?? null);

        if (($width !== null && $width >= 3800) || ($height !== null && $height >= 2000)) {
            return '4K';
        }

        if ($height !== null && $height >= 1000) {
            return '1080p';
        }

        if ($height !== null && $height >= 700) {
            return '720p';
        }

        return $height !== null ? $height.'p' : '';
    }

    /**
     * @param  array<string, mixed>  $streamStats
     */
    public function detectAudio(array $streamStats): string
    {
        $codec = $this->formatAudioCodec($this->stringValue($streamStats['audio_codec'] ?? null));
        $channels = $this->formatAudioChannels($streamStats['audio_channels'] ?? $streamStats['channels'] ?? null);

        return trim(implode(' ', array_filter([$codec, $channels])));
    }

    /**
     * @param  array<string, mixed>  $streamStats
     */
    public function detectVideoCodec(array $streamStats): string
    {
        return match (Str::of($this->stringValue($streamStats['video_codec'] ?? $streamStats['codec_name'] ?? null))->lower()->replace(['_', '-'], ' ')->squish()->value()) {
            'h264', 'h 264', 'avc', 'mpeg4 avc' => 'H.264',
            'h265', 'h 265', 'hevc' => 'H.265',
            'av1' => 'AV1',
            'vp9' => 'VP9',
            'vp8' => 'VP8',
            'mpeg2video', 'mpeg 2 video', 'mpeg2' => 'MPEG-2',
            default => Str::of($this->stringValue($streamStats['video_codec'] ?? $streamStats['codec_name'] ?? null))->upper()->value(),
        };
    }

    /**
     * @param  array<string, mixed>  $streamStats
     */
    public function detectHdr(array $streamStats): string
    {
        $hdr = Str::of($this->stringValue($streamStats['hdr'] ?? $streamStats['dynamic_range'] ?? null))->lower();
        $profile = Str::of($this->stringValue($streamStats['video_profile'] ?? $streamStats['profile'] ?? null))->lower();
        $colorTransfer = Str::of($this->stringValue($streamStats['color_transfer'] ?? null))->lower();
        $combined = Str::of($hdr.' '.$profile.' '.$colorTransfer);

        if ($combined->contains(['dolby vision', 'dovi', 'dvhe'])) {
            return 'DV';
        }

        if ($combined->contains(['hdr10+', 'smpte2094'])) {
            return 'HDR10+';
        }

        if ($combined->contains(['hdr', 'smpte2084', 'arib-std-b67', 'hlg'])) {
            return 'HDR';
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $streamStats
     * @return array<string, mixed>
     */
    private function normalizeStreamStats(array $streamStats): array
    {
        if ($this->hasNamedStats($streamStats)) {
            return $streamStats;
        }

        $video = null;
        $audio = null;

        foreach ($streamStats as $entry) {
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
            'resolution' => ($video['width'] ?? null) && ($video['height'] ?? null) ? $video['width'].'x'.$video['height'] : null,
            'width' => $video['width'] ?? null,
            'height' => $video['height'] ?? null,
            'video_codec' => $video['codec_name'] ?? null,
            'video_profile' => $video['profile'] ?? null,
            'hdr' => $video['hdr'] ?? null,
            'color_transfer' => $video['color_transfer'] ?? null,
            'audio_codec' => $audio['codec_name'] ?? null,
            'audio_channels' => $audio['channels'] ?? $audio['channel_layout'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $streamStats
     */
    private function hasNamedStats(array $streamStats): bool
    {
        return array_key_exists('resolution', $streamStats)
            || array_key_exists('video_codec', $streamStats)
            || array_key_exists('audio_codec', $streamStats);
    }

    /**
     * @param  array<string>  $keys
     */
    private function manualValue(Channel $channel, StreamFileSetting $setting, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->scalarAttribute($channel, $key);
            if ($value !== '') {
                return $value;
            }

            $value = $this->scalarAttribute($setting, $key);
            if ($value !== '') {
                return $value;
            }

            $value = $this->scalarAttribute($channel, 'manual_'.$key);
            if ($value !== '') {
                return $value;
            }

            $value = $this->scalarAttribute($setting, 'manual_'.$key);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $streamStats
     */
    private function quality(Channel $channel, StreamFileSetting $setting, array $streamStats): string
    {
        if ((bool) ($setting->use_stream_stats ?? true)) {
            return $this->detectQuality($streamStats);
        }

        return $this->manualValue($channel, $setting, ['quality', 'resolution']);
    }

    private function movieTitle(Channel $channel): string
    {
        return $this->scalarAttribute($channel, 'name_custom')
            ?: $this->scalarAttribute($channel, 'title_custom')
            ?: $this->scalarAttribute($channel, 'name')
            ?: $this->scalarAttribute($channel, 'title')
            ?: 'Unnamed';
    }

    private function movieYear(Channel $channel): string
    {
        $year = $this->scalarAttribute($channel, 'year')
            ?: $this->scalarFromArray($channel->info ?? [], ['year', 'release_year', 'releasedate', 'release_date'])
            ?: $this->scalarFromArray($channel->movie_data ?? [], ['year', 'release_year', 'releasedate', 'release_date']);

        if (preg_match('/(?<year>19\d{2}|20\d{2})/', $year, $matches) === 1) {
            return $matches['year'];
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string>  $keys
     */
    private function scalarFromArray(array $values, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $this->stringValue($values[$key] ?? null);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function scalarAttribute(object $object, string $key): string
    {
        return $this->stringValue(data_get($object, $key));
    }

    private function stringValue(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return trim((string) $value);
    }

    private function intValue(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function formatOptional(string $value, string $prefix = '', string $suffix = ''): string
    {
        return $value !== '' ? $prefix.$value.$suffix : '';
    }

    private function formatAudioCodec(string $codec): string
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

    private function formatAudioChannels(mixed $channels): string
    {
        $value = Str::of($this->stringValue($channels))->lower()->replace([' ', '_'], '')->value();

        return match ($value) {
            '1', 'mono' => '1.0',
            '2', 'stereo' => '2.0',
            '6', '5.1', '5-1' => '5.1',
            '8', '7.1', '7-1' => '7.1',
            default => $this->stringValue($channels),
        };
    }

    private function cleanUnfilledPlaceholders(string $fileName): string
    {
        $fileName = preg_replace('/\{[^}]+\}/', '', $fileName) ?? $fileName;
        $fileName = preg_replace('/\s+/', ' ', $fileName) ?? $fileName;
        $fileName = preg_replace('/\s+([\]\)])/u', '$1', $fileName) ?? $fileName;
        $fileName = preg_replace('/([\[\(])\s+/u', '$1', $fileName) ?? $fileName;
        $fileName = preg_replace('/\[(?:\s|,|-)*\]/u', '', $fileName) ?? $fileName;
        $fileName = preg_replace('/\((?:\s|,|-)*\)/u', '', $fileName) ?? $fileName;
        $fileName = preg_replace('/\s+-\s*$/u', '', $fileName) ?? $fileName;

        return trim($fileName);
    }
}
