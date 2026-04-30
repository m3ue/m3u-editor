<?php

namespace App\Services;

use App\Models\Episode;
use App\Models\Season;
use App\Models\Series;
use App\Models\StreamFileSetting;
use Illuminate\Support\Str;

class SerieFileNameService
{
    public function generateEpisodeFileName(Episode $episode, StreamFileSetting $setting): string
    {
        if (! $episode->relationLoaded('season')) {
            $episode->load('season');
        }

        if ($episode->relationLoaded('season') && $episode->getRelation('season') !== null && ! $episode->getRelation('season')->relationLoaded('series')) {
            $episode->getRelation('season')->load('series');
        }

        if (! $episode->relationLoaded('series')) {
            $episode->load('series');
        }

        $format = $setting->episode_format ?: '{title} - S{season}E{episode} - {ep_title}';
        $stats = $this->normaliseStreamStats($episode->stream_stats ?? []);
        $episodeTitle = $this->safeName($episode->title);
        $quality = $this->safeName($this->detectQuality($stats));
        $audio = $this->safeName($this->detectAudio($stats));
        $video = $this->safeName($this->detectVideo($stats));
        $hdr = $this->safeName($this->detectHdr($stats));
        $group = $this->safeName($episode->release_group);

        $fileName = strtr($format, [
            '{title}' => $this->safeName($this->serieName($episode)),
            '{season}' => $this->padNumber($episode->season ?? $episode->season_number ?? $episode->season?->season_number),
            '{episode}' => $this->padNumber($episode->episode_num ?? $episode->episode_number),
            '{ep_title}' => $episodeTitle,
            '{-title}' => $episodeTitle === '' ? '' : ' - '.$episodeTitle,
            '{quality}' => $quality,
            '{audio}' => $audio,
            '{video}' => $video,
            '{hdr}' => $hdr,
            '{group}' => $group,
            '{-group}' => $group === '' ? '' : '-'.$group,
        ]);

        return $this->cleanGeneratedName($fileName);
    }

    public function generateSeasonFolderName(Season $season): string
    {
        return 'Season '.$this->padNumber($season->season_number);
    }

    public function generateSerieFolderName(Series $serie): string
    {
        return $this->safeName($serie->name);
    }

    public function generateFullPath(Episode $episode, StreamFileSetting $setting): string
    {
        if (! $episode->relationLoaded('season')) {
            $episode->load('season');
        }

        if ($episode->relationLoaded('season') && $episode->getRelation('season') !== null && ! $episode->getRelation('season')->relationLoaded('series')) {
            $episode->getRelation('season')->load('series');
        }

        if (! $episode->relationLoaded('series')) {
            $episode->load('series');
        }

        $serie = $episode->season?->series ?? $episode->series;

        return collect([
            $serie instanceof Series ? $this->generateSerieFolderName($serie) : $this->safeName($this->serieName($episode)),
            $episode->season instanceof Season ? $this->generateSeasonFolderName($episode->season) : 'Season '.$this->padNumber($episode->season ?? $episode->season_number),
            $this->generateEpisodeFileName($episode, $setting).'.strm',
        ])->implode('/');
    }

    private function serieName(Episode $episode): string
    {
        return $episode->season?->series?->name
            ?? $episode->series?->name
            ?? 'Unknown Series';
    }

    private function padNumber(mixed $number): string
    {
        return str_pad((string) ((int) $number), 2, '0', STR_PAD_LEFT);
    }

    private function safeName(mixed $value): string
    {
        $name = trim((string) $value);

        if ($name === '') {
            return '';
        }

        $name = str_replace(['/', '\\'], ' ', $name);
        $name = preg_replace('/[<>:"|?*\x00-\x1F]/u', '', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name, " .\t\n\r\0\x0B");
    }

    private function cleanGeneratedName(string $fileName): string
    {
        $fileName = preg_replace('/\s+/u', ' ', $fileName) ?? $fileName;
        $fileName = preg_replace('/\s+-\s+(?=\.|$)/u', '', $fileName) ?? $fileName;
        $fileName = preg_replace('/\s*\[\s*\]/u', '', $fileName) ?? $fileName;
        $fileName = preg_replace('/\s*\(\s*\)/u', '', $fileName) ?? $fileName;

        return trim($fileName, " .\t\n\r\0\x0B");
    }

    /**
     * @return array{video: ?array<string, mixed>, audio: ?array<string, mixed>, flat: array<string, mixed>}
     */
    private function normaliseStreamStats(mixed $streamStats): array
    {
        $stats = is_array($streamStats) ? $streamStats : [];
        $video = null;
        $audio = null;

        // Check if this is a flat stats array (keys like resolution, video_codec, etc.)
        if (isset($stats['resolution']) || isset($stats['video_codec']) || isset($stats['audio_codec'])) {
            return [
                'video' => null,
                'audio' => null,
                'flat' => $stats,
            ];
        }

        foreach ($stats as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $stream = $entry['stream'] ?? $entry;

            if (($stream['codec_type'] ?? null) === 'video' && $video === null) {
                $video = $stream;
            }

            if (($stream['codec_type'] ?? null) === 'audio' && $audio === null) {
                $audio = $stream;
            }
        }

        return [
            'video' => $video,
            'audio' => $audio,
            'flat' => $stats,
        ];
    }

    /**
     * @param  array{video: ?array<string, mixed>, audio: ?array<string, mixed>, flat: array<string, mixed>}  $stats
     */
    private function detectQuality(array $stats): string
    {
        $resolution = $stats['flat']['resolution'] ?? null;

        if (is_string($resolution)) {
            // Match WIDTHxHEIGHT pattern first
            if (preg_match('/(?<width>\d{3,5})\s*[x×]\s*(?<height>\d{3,5})/i', $resolution, $matches) === 1) {
                $height = (int) $matches['height'];

                return match (true) {
                    $height >= 2160 => '2160p',
                    $height >= 1440 => '1440p',
                    $height >= 1080 => '1080p',
                    $height >= 720 => '720p',
                    $height > 0 => $height.'p',
                    default => '',
                };
            }

            // Match standalone height like 1080p
            if (preg_match('/(?<height>2160|1440|1080|720|480|576)p?/i', $resolution, $matches) === 1) {
                return $matches['height'].'p';
            }
        }

        $height = (int) ($stats['video']['height'] ?? 0);

        return match (true) {
            $height >= 2160 => '2160p',
            $height >= 1440 => '1440p',
            $height >= 1080 => '1080p',
            $height >= 720 => '720p',
            $height > 0 => $height.'p',
            default => '',
        };
    }

    /**
     * @param  array{video: ?array<string, mixed>, audio: ?array<string, mixed>, flat: array<string, mixed>}  $stats
     */
    private function detectAudio(array $stats): string
    {
        $codec = $stats['flat']['audio_codec'] ?? $stats['audio']['codec_name'] ?? null;
        $channels = $stats['flat']['audio_channels'] ?? $stats['audio']['channel_layout'] ?? $stats['audio']['channels'] ?? null;

        return trim(collect([$this->normaliseCodec($codec), $this->normaliseChannels($channels)])->filter()->implode(' '));
    }

    /**
     * @param  array{video: ?array<string, mixed>, audio: ?array<string, mixed>, flat: array<string, mixed>}  $stats
     */
    private function detectVideo(array $stats): string
    {
        return $this->normaliseCodec($stats['flat']['video_codec'] ?? $stats['video']['codec_name'] ?? null);
    }

    /**
     * @param  array{video: ?array<string, mixed>, audio: ?array<string, mixed>, flat: array<string, mixed>}  $stats
     */
    private function detectHdr(array $stats): string
    {
        $values = collect([
            $stats['flat']['hdr'] ?? null,
            $stats['video']['color_transfer'] ?? null,
            $stats['video']['color_space'] ?? null,
            $stats['video']['color_primaries'] ?? null,
            $stats['video']['side_data_list'][0]['side_data_type'] ?? null,
        ])->filter()->map(fn (mixed $value): string => Str::lower((string) $value))->implode(' ');

        return match (true) {
            str_contains($values, 'dv') || str_contains($values, 'dolby') => 'DV',
            str_contains($values, 'smpte2084') || str_contains($values, 'hdr10') || str_contains($values, 'bt2020') => 'HDR',
            str_contains($values, 'arib-std-b67') || str_contains($values, 'hlg') => 'HLG',
            default => '',
        };
    }

    private function normaliseCodec(mixed $codec): string
    {
        $normalized = Str::of((string) $codec)->lower()->replace(['_', '-'], ' ')->squish()->value();

        return match ($normalized) {
            'h264', 'h 264', 'avc' => 'H.264',
            'hevc', 'h265', 'h 265' => 'H.265',
            'aac' => 'AAC',
            'ac3' => 'AC-3',
            'eac3', 'e ac3', 'e ac 3' => 'E-AC-3',
            'dts' => 'DTS',
            'truehd' => 'TrueHD',
            'flac' => 'FLAC',
            default => Str::of((string) $codec)->upper()->value(),
        };
    }

    private function normaliseChannels(mixed $channels): string
    {
        if (is_numeric($channels)) {
            return match ((int) $channels) {
                1 => '1.0',
                2 => '2.0',
                6 => '5.1',
                8 => '7.1',
                default => (string) $channels,
            };
        }

        return match (Str::lower((string) $channels)) {
            'mono' => '1.0',
            'stereo' => '2.0',
            '5.1', '5.1(side)' => '5.1',
            '7.1' => '7.1',
            default => (string) $channels,
        };
    }
}
