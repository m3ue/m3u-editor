<?php

namespace App\Services;

use App\Models\Episode;
use App\Models\Season;
use App\Models\Series;
use App\Models\StreamFileSetting;

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

        $format = $setting->episode_format ?: '{title} - S{season}E{episode}{-title}';
        $stats = StreamStatsService::normalize($episode->stream_stats ?? []);
        $episodeTitle = $this->safeName($episode->title);

        $titleHaystack = trim((string) ($episode->title ?? '').' '.($episode->name ?? ''));
        $quality = $this->safeName(StreamStatsService::detectQuality($stats)) ?: $this->safeName(TitleMetadataParser::detectQuality($titleHaystack));
        $audio = $this->safeName(StreamStatsService::detectAudio($stats)) ?: $this->safeName(TitleMetadataParser::detectAudio($titleHaystack));
        $video = $this->safeName(StreamStatsService::detectVideoCodec($stats));
        $hdr = $this->safeName(StreamStatsService::detectHdr($stats)) ?: $this->safeName(TitleMetadataParser::detectHdr($titleHaystack));
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
            '{group}' => '',
            '{-group}' => '',
        ]);

        // Trash Guide naming: append components bracket (mirrors UI preview)
        if ($setting->trash_guide_naming_enabled) {
            $components = $setting->trash_episode_components ?? ['quality', 'video', 'audio', 'hdr'];
            $map = ['quality' => $quality, 'video' => $video, 'audio' => $audio, 'hdr' => $hdr];
            $parts = [];
            foreach (['quality', 'video', 'audio', 'hdr'] as $key) {
                if (in_array($key, $components, true) && ! empty($map[$key])) {
                    $parts[] = $map[$key];
                }
            }
            if ($parts) {
                $fileName .= ' ['.implode(' ', $parts).']';
            }
        }

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
}
