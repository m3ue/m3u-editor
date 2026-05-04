<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\StreamFileSetting;

class VodFileNameService
{
    /**
     * Built-in edition keywords (TRaSH Guide compatible).
     * Order matters — longer/more-specific phrases first.
     *
     * @var array<string, string> pattern => canonical name (Plex {edition-X} compatible)
     */
    private const EDITION_KEYWORDS = [
        "DIRECTOR['’]?S\\s*CUT" => "Director's Cut",
        'EXTENDED\\s*CUT' => 'Extended Cut',
        'EXTENDED\\s*EDITION' => 'Extended Edition',
        'EXTENDED' => 'Extended',
        'FINAL\\s*CUT' => 'Final Cut',
        'THE\\s*FINAL\\s*CUT' => 'Final Cut',
        'THEATRICAL\\s*CUT' => 'Theatrical Cut',
        'THEATRICAL' => 'Theatrical',
        'UNRATED' => 'Unrated',
        'UNCUT' => 'Uncut',
        'REMASTERED' => 'Remastered',
        '4K\\s*REMASTERED' => '4K Remastered',
        'IMAX(?:\\s*ENHANCED)?' => 'IMAX',
        'ULTIMATE\\s*EDITION' => 'Ultimate Edition',
        'COLLECTOR[\']?S?\\s*EDITION' => "Collector's Edition",
        'SPECIAL\\s*EDITION' => 'Special Edition',
        'LIMITED\\s*EDITION' => 'Limited Edition',
        'ANNIVERSARY\\s*EDITION' => 'Anniversary Edition',
        '\\d+TH\\s*ANNIVERSARY(?:\\s*EDITION)?' => 'Anniversary Edition',
        'OPEN\\s*MATTE' => 'Open Matte',
        'REDUX' => 'Redux',
        'RECUT' => 'Recut',
        'CRITERION(?:\\s*COLLECTION)?' => 'Criterion Collection',
        'ROGUE\\s*CUT' => 'Rogue Cut',
        'HYBRID' => 'Hybrid',
        'DUBBED' => 'Dubbed',
    ];

    /**
     * Detect a known edition tag in arbitrary text. Returns the canonical name or ''.
     */
    public static function detectEdition(?string $text): string
    {
        if (! is_string($text) || $text === '') {
            return '';
        }

        foreach (self::EDITION_KEYWORDS as $pattern => $canonical) {
            if (preg_match('/\b'.$pattern.'\b/i', $text) === 1) {
                return $canonical;
            }
        }

        return '';
    }

    /**
     * Strip detected edition tokens from a movie title (so they don't appear twice
     * once we re-append them as {edition-X} or [Edition]).
     */
    public static function stripEditionFromTitle(string $title): string
    {
        foreach (self::EDITION_KEYWORDS as $pattern => $_canonical) {
            $title = preg_replace('/\s*[\[\(\-]*\s*\b'.$pattern.'\b\s*[\]\)\-]*/i', ' ', $title) ?? $title;
        }
        $title = preg_replace('/\s+/', ' ', $title) ?? $title;

        return trim($title);
    }

    /**
     * Resolve the edition for a channel: explicit attribute first, then auto-detect
     * from title / name / movie_data.
     */
    public function resolveEdition(Channel $channel): string
    {
        $explicit = $this->scalarAttribute($channel, 'edition');
        if ($explicit !== '') {
            return $explicit;
        }

        $haystacks = [
            $this->scalarAttribute($channel, 'title_custom'),
            $this->scalarAttribute($channel, 'title'),
            $this->scalarAttribute($channel, 'name_custom'),
            $this->scalarAttribute($channel, 'name'),
            $this->scalarFromArray($channel->info ?? [], ['edition', 'movie_edition', 'release_name']),
            $this->scalarFromArray($channel->movie_data ?? [], ['edition', 'movie_edition', 'release_name']),
        ];
        foreach ($haystacks as $h) {
            $found = self::detectEdition($h);
            if ($found !== '') {
                return $found;
            }
        }

        return '';
    }

    /**
     * Generate ONLY the trash-guide extras (edition + quality bracket) to be appended
     * to the standard filename. Does not include title/year/tmdb/group.
     */
    public function generateMovieExtras(Channel $channel, StreamFileSetting $setting): string
    {
        $components = $setting->trash_movie_components ?? ['quality', 'video', 'audio', 'hdr'];
        $usePlexMarker = (bool) ($setting->group_versions ?? true);

        $streamStats = StreamStatsService::normalize($channel->stream_stats ?? []);
        $hasStats = ! empty($streamStats);

        $values = [
            'edition' => $this->resolveEdition($channel),
            'quality' => $this->quality($channel, $setting, $streamStats, $hasStats),
            'video' => $this->preferStatsOrManual($hasStats ? StreamStatsService::detectVideoCodec($streamStats) : '', fn () => $this->manualValue($channel, $setting, ['video', 'video_format', 'video_codec'])),
            'audio' => $this->preferStatsManualOrTitle($hasStats ? StreamStatsService::detectAudio($streamStats) : '', fn () => $this->manualValue($channel, $setting, ['audio', 'audio_format', 'audio_codec']), fn () => TitleMetadataParser::detectAudio($this->rawTitle($channel))),
            'hdr' => $this->preferStatsManualOrTitle($hasStats ? StreamStatsService::detectHdr($streamStats) : '', fn () => $this->manualValue($channel, $setting, ['hdr', 'hdr_format']), fn () => TitleMetadataParser::detectHdr($this->rawTitle($channel))),
        ];

        $out = '';
        if (in_array('edition', $components, true) && $values['edition'] !== '') {
            // Plex / Jellyfin / Emby read {edition-Name} as a multi-version marker —
            // multiple files in the same movie folder become switchable versions.
            $out .= $usePlexMarker
                ? ' {edition-'.$values['edition'].'}'
                : ' '.$values['edition'];
        }

        $bracket = [];
        foreach (['quality', 'video', 'audio', 'hdr'] as $b) {
            if (in_array($b, $components, true) && $values[$b] !== '') {
                $bracket[] = $values[$b];
            }
        }
        if ($bracket) {
            $out .= ' ['.implode(' ', $bracket).']';
        }

        return trim(preg_replace('/\s+/', ' ', $out));
    }

    /**
     * Generate a filesystem-safe VOD movie filename from the configured format.
     */
    public function generateMovieFileName(Channel $channel, StreamFileSetting $setting): string
    {
        $format = filled($setting->movie_format ?? null)
            ? (string) $setting->movie_format
            : '{title} ({year}) {edition} [{quality} {video} {audio} {hdr}]';

        $streamStats = StreamStatsService::normalize($channel->stream_stats ?? []);
        $hasStats = ! empty($streamStats);

        $replacements = [
            '{title}' => PlaylistService::makeFilesystemSafe($this->movieTitle($channel), $setting->replace_char ?? 'space'),
            '{year}' => $this->movieYear($channel),
            '{edition}' => $this->formatOptional($this->scalarAttribute($channel, 'edition'), prefix: ' '),
            '{quality}' => $this->quality($channel, $setting, $streamStats, $hasStats),
            '{audio}' => $this->preferStatsManualOrTitle($hasStats ? StreamStatsService::detectAudio($streamStats) : '', fn () => $this->manualValue($channel, $setting, ['audio', 'audio_format', 'audio_codec']), fn () => TitleMetadataParser::detectAudio($this->rawTitle($channel))),
            '{video}' => $this->preferStatsOrManual($hasStats ? StreamStatsService::detectVideoCodec($streamStats) : '', fn () => $this->manualValue($channel, $setting, ['video', 'video_format', 'video_codec'])),
            '{hdr}' => $this->preferStatsManualOrTitle($hasStats ? StreamStatsService::detectHdr($streamStats) : '', fn () => $this->manualValue($channel, $setting, ['hdr', 'hdr_format']), fn () => TitleMetadataParser::detectHdr($this->rawTitle($channel))),
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
    private function quality(Channel $channel, StreamFileSetting $setting, array $streamStats, bool $hasStats): string
    {
        if ($hasStats) {
            $detected = StreamStatsService::detectQuality($streamStats);
            if ($detected !== '') {
                return $detected;
            }
        }

        $manual = $this->manualValue($channel, $setting, ['quality', 'resolution']);
        if ($manual !== '') {
            return $manual;
        }

        return TitleMetadataParser::detectQuality($this->rawTitle($channel));
    }

    /**
     * Get the channel's raw title-like fields for title-based metadata extraction.
     */
    private function rawTitle(Channel $channel): string
    {
        return trim(implode(' ', array_filter([
            $this->scalarAttribute($channel, 'title'),
            $this->scalarAttribute($channel, 'name'),
            $this->scalarAttribute($channel, 'title_custom'),
        ])));
    }

    /**
     * Stats → manual → title-based fallback chain.
     */
    private function preferStatsManualOrTitle(string $statsValue, \Closure $manualFallback, \Closure $titleFallback): string
    {
        if ($statsValue !== '') {
            return $statsValue;
        }
        $manual = $manualFallback();
        if ($manual !== '') {
            return $manual;
        }

        return $titleFallback();
    }

    private function preferStatsOrManual(string $statsValue, \Closure $manualFallback): string
    {
        if ($statsValue !== '') {
            return $statsValue;
        }

        return $manualFallback();
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
