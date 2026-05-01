<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\StreamFileSetting;

class VodFileNameService
{
    /**
     * Generate a filesystem-safe VOD movie filename from the configured format.
     */
    public function generateMovieFileName(Channel $channel, StreamFileSetting $setting): string
    {
        $format = filled($setting->movie_format ?? null)
            ? (string) $setting->movie_format
            : '{title} ({year}) {edition} [{quality} {video} {audio} {hdr}]';

        $streamStats = (bool) ($setting->use_stream_stats ?? true)
            ? StreamStatsService::normalize($channel->stream_stats ?? [])
            : [];

        $replacements = [
            '{title}' => PlaylistService::makeFilesystemSafe($this->movieTitle($channel), $setting->replace_char ?? 'space'),
            '{year}' => $this->movieYear($channel),
            '{edition}' => $this->formatOptional($this->scalarAttribute($channel, 'edition'), prefix: ' '),
            '{quality}' => $this->quality($channel, $setting, $streamStats),
            '{audio}' => (bool) ($setting->use_stream_stats ?? true) ? StreamStatsService::detectAudio($streamStats) : $this->manualValue($channel, $setting, ['audio', 'audio_format', 'audio_codec']),
            '{video}' => (bool) ($setting->use_stream_stats ?? true) ? StreamStatsService::detectVideoCodec($streamStats) : $this->manualValue($channel, $setting, ['video', 'video_format', 'video_codec']),
            '{hdr}' => (bool) ($setting->use_stream_stats ?? true) ? StreamStatsService::detectHdr($streamStats) : $this->manualValue($channel, $setting, ['hdr', 'hdr_format']),
            '{group}' => '',
            '{-group}' => '',
        ];

        $fileName = strtr($format, $replacements);
        $fileName = $this->cleanUnfilledPlaceholders($fileName);

        return PlaylistService::makeFilesystemSafe($fileName, $setting->replace_char ?? 'space');
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
            return StreamStatsService::detectQuality($streamStats);
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
