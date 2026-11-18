<?php

namespace App\Services;

use Illuminate\Support\Arr;

/**
 * Normalizes AIOStreams "stream" objects (from AIOStreamsService::fetchStreams())
 * into a consistent shape for ranking/filtering, and provides the default
 * ranking used to pick failover candidates.
 *
 * AIOStreams' structured per-stream fields aren't publicly documented and vary
 * by upstream addon, so this primarily regex/keyword-matches the human-readable
 * name/title/description strings (the one thing consistently present across
 * addons), falling back to structured fields (e.g. behaviorHints) when present.
 */
class AioStreamsQualityParser
{
    /**
     * Recognized VOD container extensions — deliberately excludes ts/m3u8,
     * which are live-stream formats. If a stream's container can't be
     * determined to be one of these, the caller should treat it as unknown
     * (Channel/Episode::getProxyUrl() already default sanely) rather than
     * guessing 'ts', which makes players treat VOD content as live and
     * disable seeking.
     */
    protected const VALID_CONTAINERS = ['mp4', 'mkv', 'avi', 'webm', 'mov', 'm4v', 'flv'];

    /**
     * Filename tokens per language (regex alternations, matched whole-word and
     * case-insensitively).
     */
    protected const LANGUAGE_TOKENS = [
        'multi' => 'MULTI|MULTi|DUAL|DUAL[-. ]AUDIO|DUBLADO',
        'en' => 'ENG|ENGLISH',
        'fr' => 'FRENCH|TRUEFRENCH|VFF|VFQ|VFI|VF2|VF|VOF|FRA|FRE',
        'de' => 'GERMAN|GER|DEU',
        'es' => 'SPANISH|SPA|ESP|ESPANOL|CASTELLANO|LATINO|LAT',
        'it' => 'ITALIAN|ITA',
        'pt' => 'PORTUGUESE|POR|PT-BR|PTBR',
        'ru' => 'RUSSIAN|RUS',
        'uk' => 'UKRAINIAN|UKR',
        'pl' => 'POLISH|POL|PL|LEKTOR',
        'nl' => 'DUTCH|NLD',
        'sv' => 'SWEDISH|SWE',
        'da' => 'DANISH|DAN',
        'no' => 'NORWEGIAN|NOR',
        'fi' => 'FINNISH|FIN',
        'ja' => 'JAPANESE|JPN|JAP',
        'ko' => 'KOREAN|KOR',
        'zh' => 'CHINESE|CHI|MANDARIN|CANTONESE',
        'hi' => 'HINDI|HIN',
        'ar' => 'ARABIC|ARA',
        'tr' => 'TURKISH|TUR',
        'he' => 'HEBREW|HEB',
        'cs' => 'CZECH|CZE',
        'hu' => 'HUNGARIAN|HUN',
        'el' => 'GREEK|GRE',
    ];

    protected const LANGUAGE_FLAGS = [
        '🇬🇧' => 'en', '🇺🇸' => 'en', '🇫🇷' => 'fr', '🇩🇪' => 'de', '🇪🇸' => 'es', '🇲🇽' => 'es',
        '🇮🇹' => 'it', '🇵🇹' => 'pt', '🇧🇷' => 'pt', '🇷🇺' => 'ru', '🇺🇦' => 'uk', '🇵🇱' => 'pl',
        '🇳🇱' => 'nl', '🇸🇪' => 'sv', '🇩🇰' => 'da', '🇳🇴' => 'no', '🇫🇮' => 'fi', '🇯🇵' => 'ja',
        '🇰🇷' => 'ko', '🇨🇳' => 'zh', '🇮🇳' => 'hi', '🇸🇦' => 'ar', '🇹🇷' => 'tr', '🇮🇱' => 'he',
        '🇨🇿' => 'cs', '🇭🇺' => 'hu', '🇬🇷' => 'el',
    ];

    /**
     * @param  array<string, mixed>  $stream  A single entry from the `streams` array
     *                                        returned by AIOStreamsService::fetchStreams().
     * @return array{
     *     resolution: ?int,
     *     hdr: bool,
     *     dv: bool,
     *     codec: ?string,
     *     container: ?string,
     *     audio_channels: ?string,
     *     audio_format: ?string,
     *     size_bytes: ?int,
     *     seeders: ?int,
     *     cached: bool,
     *     source_addon: ?string,
     *     source: ?string,
     *     languages: array<int, string>,
     *     release_group: ?string,
     * }
     */
    public function parse(array $stream): array
    {
        $haystack = collect([
            $stream['name'] ?? null,
            $stream['title'] ?? null,
            $stream['description'] ?? null,
        ])->filter()->implode(' ');

        $behaviorHints = $stream['behaviorHints'] ?? [];
        $releaseName = $this->releaseName($stream);
        $bingeGroup = explode('|', (string) ($behaviorHints['bingeGroup'] ?? ''));

        return [
            // The filename/bingeGroup fallback only fills a resolution the display
            // text didn't state - every resolution found before is unchanged.
            'resolution' => $this->parseResolution($haystack)
                ?? $this->parseResolution($releaseName.' '.($bingeGroup[1] ?? '')),
            'hdr' => (bool) preg_match('/\bHDR(10\+?)?\b/i', $haystack),
            'dv' => (bool) preg_match('/\bDV\b|Dolby[\s._-]?Vision/i', $haystack),
            'codec' => $this->parseCodec($haystack),
            'container' => $this->parseContainer($stream, $behaviorHints, $haystack),
            'audio_channels' => $this->parseAudioChannels($haystack),
            'audio_format' => $this->parseAudioFormat($haystack),
            'size_bytes' => $behaviorHints['videoSize'] ?? $this->parseSizeBytes($haystack),
            'seeders' => $stream['seeders'] ?? $this->parseSeeders($haystack),
            'cached' => (bool) ($behaviorHints['cached'] ?? preg_match('/\b(cached|instant)\b/i', $haystack)),
            'source_addon' => $stream['name'] ?? null,
            'source' => $this->parseSource($bingeGroup[2] ?? '', $releaseName),
            'languages' => $this->parseLanguages($releaseName, (string) ($stream['description'] ?? '')),
            'release_group' => $this->parseReleaseGroup($releaseName, $bingeGroup),
        ];
    }

    /**
     * The release/file name: the most honest signal for source and audio
     * language. Display text (name/description) depends entirely on the user's
     * AIOStreams formatter - e.g. one formatter tags every stream "EN" in
     * small caps regardless of the actual audio, while the filename says
     * "MULTi.VFQ" or is a Cyrillic dub release.
     *
     * @param  array<string, mixed>  $stream
     */
    protected function releaseName(array $stream): string
    {
        $name = (string) ($stream['behaviorHints']['filename'] ?? $stream['title'] ?? '');

        return (string) preg_replace('/\.(mkv|mp4|avi|webm|mov|m4v|flv|ts)$/i', '', trim($name));
    }

    /**
     * Fold the decorative Unicode some formatters use (small capitals,
     * zero-width joiners) back to plain text so keywords like "remux" match
     * "ʀᴇᴍᴜx".
     */
    public static function normalizeText(string $text): string
    {
        $text = (string) preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text);

        return strtr($text, [
            'ᴀ' => 'a', 'ʙ' => 'b', 'ᴄ' => 'c', 'ᴅ' => 'd', 'ᴇ' => 'e', 'ꜰ' => 'f', 'ɢ' => 'g', 'ʜ' => 'h',
            'ɪ' => 'i', 'ᴊ' => 'j', 'ᴋ' => 'k', 'ʟ' => 'l', 'ᴍ' => 'm', 'ɴ' => 'n', 'ᴏ' => 'o', 'ᴘ' => 'p',
            'ǫ' => 'q', 'ʀ' => 'r', 'ꜱ' => 's', 'ᴛ' => 't', 'ᴜ' => 'u', 'ᴠ' => 'v', 'ᴡ' => 'w', 'ʏ' => 'y', 'ᴢ' => 'z',
        ]);
    }

    /**
     * Release source, worst-first so a "TS" or "CAM" tag wins over anything
     * else the name mentions. AIOStreams' bingeGroup quality ("BluRay REMUX",
     * "WEB-DL") is structured, so it's trusted first; the release name is
     * only read past its title (see releaseTagParts()), so "Cam" (2018) or
     * "Charlotte's Web" don't read as sources.
     */
    protected function parseSource(string $bingeGroupQuality, string $releaseName): ?string
    {
        [$tags, $titleGap] = $this->releaseTagParts($releaseName);

        foreach ([[$bingeGroupQuality, ''], [$tags, $titleGap]] as [$text, $gap]) {
            foreach (self::SOURCE_PATTERNS as $source => $pattern) {
                if ($this->hasReleaseTag($pattern, $text, $gap)) {
                    return $source;
                }
            }
        }

        return null;
    }

    /**
     * Audio language codes for a release (ISO 639-1, plus 'multi' for
     * multi/dual-audio releases). Empty when the release doesn't tag its
     * audio, which by scene convention means the original language.
     *
     * Language tokens only count in the tag part of the name (see
     * releaseTagParts()), so titles such as "The French Dispatch" or an
     * episode called "The Finnish Line" aren't read as audio languages.
     * Subtitle tags ("UKRsub", "VOSTFR") don't match the word-bounded tokens.
     * A Cyrillic name is a Russian release. Flag emoji in the description
     * are the one reliable display-text signal.
     *
     * @return array<int, string>
     */
    protected function parseLanguages(string $releaseName, string $description): array
    {
        [$tags, $titleGap] = $this->releaseTagParts($releaseName);
        $languages = [];

        foreach (self::LANGUAGE_TOKENS as $code => $pattern) {
            if ($this->hasReleaseTag('/(?<![a-z0-9])('.$pattern.')(?![a-z0-9])/i', $tags, $titleGap)) {
                $languages[] = $code;
            }
        }

        if (preg_match('/\p{Cyrillic}/u', $releaseName)) {
            $languages[] = 'ru';
        }

        foreach (self::LANGUAGE_FLAGS as $flag => $code) {
            if (str_contains($description, $flag)) {
                $languages[] = $code;
            }
        }

        return array_values(array_unique($languages));
    }

    /**
     * Split a release name into the part that carries its tags and, for
     * episodes, the gap that may hold the episode title.
     *
     * - Movies ("Title.2026.MULTi.2160p..."): tags start at the year (or the
     *   resolution when there's no year); the title before it is ignored.
     * - Episodes ("Show.S02E05.The.Finnish.Line.1080p..."): tags start at
     *   the resolution after SxxEyy. The words between SxxEyy and the
     *   resolution are returned separately: they may be scene tags
     *   ("Show.S01E01.FRENCH.1080p") or the episode title, which
     *   hasReleaseTag() tells apart by capitalization (and which is dropped
     *   for all-lowercase names, where it can't).
     * - No marker at all: the whole name counts, as before.
     *
     * @return array{0: string, 1: string} [tags, title gap]
     */
    protected function releaseTagParts(string $releaseName): array
    {
        $resolution = '/\b\d{3,4}p\b|\b(4K|UHD)\b/i';

        if (preg_match('/\bS\d{1,2}E\d{1,3}\b/i', $releaseName, $match, PREG_OFFSET_CAPTURE)) {
            $afterEpisode = substr($releaseName, $match[0][1] + strlen($match[0][0]));

            if (preg_match($resolution, $afterEpisode, $resolutionMatch, PREG_OFFSET_CAPTURE)) {
                $tags = substr($afterEpisode, $resolutionMatch[0][1]);
                $titleGap = substr($afterEpisode, 0, $resolutionMatch[0][1]);
            } else {
                [$tags, $titleGap] = ['', $afterEpisode];
            }

            // Capitalization is the only way to tell scene tags from title
            // words in the gap, so an all-lowercase name gets no gap at all
            // ("show.s02e03.the.french.connection.720p" isn't French audio).
            return [$tags, preg_match('/\p{Lu}/u', $releaseName) ? $titleGap : ''];
        }

        if (preg_match('/(?<!\d)(19|20)\d{2}(?!\d)/', $releaseName, $match, PREG_OFFSET_CAPTURE)
            || preg_match($resolution, $releaseName, $match, PREG_OFFSET_CAPTURE)) {
            return [substr($releaseName, $match[0][1]), ''];
        }

        return [$releaseName, ''];
    }

    /**
     * Whether a tag pattern matches: anywhere in the tag part, or in an
     * episode's title gap only when written the way scene tags are
     * (FRENCH, MULTi, iTA, WEB-DL) rather than as a capitalized title word
     * (Finnish, Cam), which is how episode titles are written.
     */
    protected function hasReleaseTag(string $pattern, string $tags, string $titleGap): bool
    {
        if ($tags !== '' && preg_match($pattern, $tags)) {
            return true;
        }

        if ($titleGap === '' || ! preg_match_all($pattern, $titleGap, $matches)) {
            return false;
        }

        foreach ($matches[0] as $token) {
            if (! preg_match('/^[A-Z][a-z]+$/', $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Release source patterns, worst first.
     */
    protected const SOURCE_PATTERNS = [
        'cam' => '/\b(HD-?)?CAM(-?RIP)?\b/i',
        'telesync' => '/\b(HD-?TS|TELESYNC|TS)\b/i',
        'telecine' => '/\b(HD-?TC|TELECINE)\b/i',
        'screener' => '/\b(DVD-?SCR|SCREENER|SCR)\b/i',
        'remux' => '/REMUX/i',
        'bluray' => '/\b(BLU-?RAY|BD-?RIP|BR-?RIP|BD25|BD50)\b/i',
        'webrip' => '/\bWEB-?RIP\b/i',
        'web-dl' => '/\bWEB([-. ]?DL)?\b/i',
        'hdtv' => '/\b(HDTV|PDTV)\b/i',
        'dvd' => '/\bDVD(-?RIP|5|9|R)?\b/i',
    ];

    /**
     * @param  array<int, string>  $bingeGroup
     */
    protected function parseReleaseGroup(string $releaseName, array $bingeGroup): ?string
    {
        if (! empty($bingeGroup[3])) {
            return $bingeGroup[3];
        }

        return preg_match('/-([A-Za-z0-9]+)$/', $releaseName, $match) ? $match[1] : null;
    }

    /**
     * Every language code a profile can prefer, plus the two pseudo-languages.
     *
     * @return array<int, string>
     */
    public static function languageCodes(): array
    {
        return ['original', ...array_keys(self::LANGUAGE_TOKENS)];
    }

    protected function parseResolution(string $haystack): ?int
    {
        if (preg_match('/\b(2160p|4k|uhd)\b/i', $haystack)) {
            return 2160;
        }
        if (preg_match('/\b1080p\b/i', $haystack)) {
            return 1080;
        }
        if (preg_match('/\b720p\b/i', $haystack)) {
            return 720;
        }
        if (preg_match('/\b(480p|sd)\b/i', $haystack)) {
            return 480;
        }

        return null;
    }

    protected function parseCodec(string $haystack): ?string
    {
        if (preg_match('/\bAV1\b/i', $haystack)) {
            return 'av1';
        }
        if (preg_match('/\b(HEVC|x265|h\.?265)\b/i', $haystack)) {
            return 'hevc';
        }
        if (preg_match('/\b(x264|h\.?264|AVC)\b/i', $haystack)) {
            return 'avc';
        }

        return null;
    }

    /**
     * Best-effort VOD container extension, checked in order of reliability:
     * the addon-provided release filename (behaviorHints.filename — Stremio
     * addons commonly include the real torrent/file name here, extension and
     * all), the stream URL's own path (rarely useful for debrid links, which
     * are usually opaque signed URLs with no extension), then a keyword match
     * against the human-readable name/title/description text.
     *
     * @param  array<string, mixed>  $stream
     * @param  array<string, mixed>  $behaviorHints
     */
    protected function parseContainer(array $stream, array $behaviorHints, string $haystack): ?string
    {
        $fromFilename = $this->extensionFromPath($behaviorHints['filename'] ?? null);
        if ($fromFilename) {
            return $fromFilename;
        }

        $fromUrl = $this->extensionFromPath($stream['url'] ?? null);
        if ($fromUrl) {
            return $fromUrl;
        }

        if (preg_match('/\.?\b('.implode('|', self::VALID_CONTAINERS).')\b/i', $haystack, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }

    protected function extensionFromPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $extension = strtolower(pathinfo(parse_url($path, PHP_URL_PATH) ?? $path, PATHINFO_EXTENSION));

        return in_array($extension, self::VALID_CONTAINERS, true) ? $extension : null;
    }

    protected function parseAudioChannels(string $haystack): ?string
    {
        if (preg_match('/\b7\.1\b/', $haystack)) {
            return '7.1';
        }
        if (preg_match('/\b5\.1\b/', $haystack)) {
            return '5.1';
        }
        if (preg_match('/\b2\.0\b|\bstereo\b/i', $haystack)) {
            return '2.0';
        }

        return null;
    }

    protected function parseAudioFormat(string $haystack): ?string
    {
        if (preg_match('/\bATMOS\b/i', $haystack)) {
            return 'atmos';
        }
        if (preg_match('/\bTrueHD\b/i', $haystack)) {
            return 'truehd';
        }
        if (preg_match('/\bDTS[\s._-]?(HD|X)?\b/i', $haystack)) {
            return 'dts';
        }
        if (preg_match('/\bAC3|DD5\.1|Dolby[\s._-]?Digital\b/i', $haystack)) {
            return 'dd';
        }
        if (preg_match('/\bAAC\b/i', $haystack)) {
            return 'aac';
        }

        return null;
    }

    protected function parseSizeBytes(string $haystack): ?int
    {
        if (! preg_match('/([\d.]+)\s?(GB|MB)\b/i', $haystack, $matches)) {
            return null;
        }

        $value = (float) $matches[1];
        $unit = strtoupper($matches[2]);

        return (int) ($unit === 'GB' ? $value * 1_073_741_824 : $value * 1_048_576);
    }

    protected function parseSeeders(string $haystack): ?int
    {
        if (! preg_match('/👤\s?(\d+)|seeders?[:\s]+(\d+)/i', $haystack, $matches)) {
            return null;
        }

        // A matched "0" (e.g. "👤 0") is a valid seeder count, not a missing one - the
        // ?: operator treats the string "0" as falsy and fell through to $matches[2],
        // which doesn't exist for this alternative, raising "Undefined array key 2".
        return (int) (($matches[1] ?? '') !== '' ? $matches[1] : $matches[2]);
    }

    /**
     * Filter out streams that violate hard exclusion rules, then rank the
     * survivors. Returns the parsed+ranked list, each entry augmented with the
     * original stream under 'stream'.
     *
     * With no rules this is the long-standing default: cached/debrid-ready
     * first, then resolution desc, then seeders/size as a tiebreak. The rules
     * are an AioStreamsMatchProfile's (see the keys below); each one only
     * changes the outcome when set.
     *
     * @param  array<int, array<string, mixed>>  $streams  Raw streams from fetchStreams().
     * @param  array<string, mixed>  $preference  See normalizeRules().
     * @return array<int, array{parsed: array<string, mixed>, stream: array<string, mixed>}>
     */
    public function rank(array $streams, array $preference = []): array
    {
        return collect($this->explain($streams, $preference))
            ->filter(fn (array $candidate) => $candidate['excluded'] === null)
            ->map(fn (array $candidate) => Arr::only($candidate, ['parsed', 'stream']))
            ->values()
            ->all();
    }

    /**
     * Every stream, ranked: the ones the rules allow first (in rank() order),
     * then the excluded ones with the reason, so the manual picker and the
     * profile tester can show users why a stream was skipped.
     *
     * @param  array<int, array<string, mixed>>  $streams
     * @param  array<string, mixed>  $preference
     * @return array<int, array{parsed: array<string, mixed>, stream: array<string, mixed>, excluded: ?string}>
     */
    public function explain(array $streams, array $preference = []): array
    {
        $rules = $this->normalizeRules($preference);

        $candidates = collect($streams)
            ->map(function (array $stream) use ($rules) {
                $parsed = $this->parse($stream);

                return [
                    'parsed' => $parsed,
                    'stream' => $stream,
                    'excluded' => $this->exclusionReason($parsed, $stream, $rules),
                ];
            });

        $sorted = $candidates->sortBy($this->comparators($rules))->values();

        return $sorted->where('excluded', null)
            ->concat($sorted->whereNotNull('excluded'))
            ->values()
            ->all();
    }

    /**
     * The same rules with every hard filter dropped, keeping only the ordering
     * preferences - used for a profile's "fall back to the best available
     * stream" when nothing passes its filters.
     *
     * @param  array<string, mixed>  $preference
     * @return array<string, mixed>
     */
    public function orderingOnly(array $preference): array
    {
        return Arr::only($this->normalizeRules($preference), [
            'resolutions',
            'languages',
            'preferred_keywords',
            'prefer_cached',
            'prefer_smaller',
        ]);
    }

    /**
     * Cast rules from a profile (or form state, where numbers arrive as
     * strings and lists may hold blanks) into a predictable shape.
     *
     * @param  array<string, mixed>  $preference
     * @return array{
     *     avoid_hdr: bool, avoid_dv: bool, avoid_lossless_audio: bool, avoid_codecs: array<int, string>,
     *     max_audio_channels: ?float, resolutions: array<int, int>, only_listed_resolutions: bool,
     *     languages: array<int, string>, require_language: bool, excluded_sources: array<int, string>,
     *     excluded_keywords: array<int, string>, preferred_keywords: array<int, string>,
     *     min_size_bytes: ?int, max_size_bytes: ?int, prefer_cached: bool, prefer_smaller: bool,
     * }
     */
    public function normalizeRules(array $preference): array
    {
        $list = fn (string $key) => collect($preference[$key] ?? [])
            ->map(fn ($value) => is_array($value) ? reset($value) : $value)
            ->filter(fn ($value) => $value !== null && $value !== '' && $value !== false)
            ->map(fn ($value) => trim((string) $value))
            ->unique()
            ->values();

        $gigabytes = fn (string $key) => is_numeric($preference[$key] ?? null) && (float) $preference[$key] > 0
            ? (int) ((float) $preference[$key] * 1_073_741_824)
            : null;

        return [
            'avoid_hdr' => (bool) ($preference['avoid_hdr'] ?? false),
            'avoid_dv' => (bool) ($preference['avoid_dv'] ?? false),
            'avoid_lossless_audio' => (bool) ($preference['avoid_lossless_audio'] ?? false),
            'avoid_codecs' => $list('avoid_codecs')->map(fn ($codec) => strtolower($codec))->all(),
            'max_audio_channels' => ! empty($preference['max_audio_channels']) ? (float) $preference['max_audio_channels'] : null,
            'resolutions' => $list('resolutions')->map(fn ($resolution) => (int) $resolution)->filter()->values()->all(),
            'only_listed_resolutions' => (bool) ($preference['only_listed_resolutions'] ?? false),
            'languages' => $list('languages')->map(fn ($language) => strtolower($language))->all(),
            'require_language' => (bool) ($preference['require_language'] ?? false),
            'excluded_sources' => $list('excluded_sources')->all(),
            'excluded_keywords' => $list('excluded_keywords')->all(),
            'preferred_keywords' => $list('preferred_keywords')->all(),
            'min_size_bytes' => $gigabytes('min_size_gb'),
            'max_size_bytes' => $gigabytes('max_size_gb'),
            'prefer_cached' => (bool) ($preference['prefer_cached'] ?? true),
            'prefer_smaller' => (bool) ($preference['prefer_smaller'] ?? false),
        ];
    }

    /**
     * Why the rules rule a stream out, or null when they allow it.
     *
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $stream
     * @param  array<string, mixed>  $rules  normalizeRules() output.
     */
    protected function exclusionReason(array $parsed, array $stream, array $rules): ?string
    {
        if ($rules['avoid_hdr'] && $parsed['hdr']) {
            return __('HDR');
        }
        if ($rules['avoid_dv'] && $parsed['dv']) {
            return __('Dolby Vision');
        }
        if ($rules['avoid_lossless_audio'] && in_array($parsed['audio_format'], ['truehd', 'dts'], true)) {
            return __('Lossless audio');
        }
        if ($parsed['codec'] && in_array($parsed['codec'], $rules['avoid_codecs'], true)) {
            return __('Codec');
        }
        if ($rules['max_audio_channels'] && $parsed['audio_channels'] && (float) $parsed['audio_channels'] > $rules['max_audio_channels']) {
            return __('Audio channels');
        }
        if ($rules['only_listed_resolutions'] && $rules['resolutions'] !== [] && ! in_array($parsed['resolution'], $rules['resolutions'], true)) {
            return __('Resolution');
        }
        if ($rules['require_language'] && $rules['languages'] !== [] && $this->languageRank($parsed['languages'], $rules['languages']) === null) {
            return __('Language');
        }
        if ($parsed['source'] && in_array($parsed['source'], $rules['excluded_sources'], true)) {
            return __('Source');
        }
        $keyword = $this->firstMatchingKeyword($stream, $rules['excluded_keywords']);
        if ($keyword !== null) {
            return __('Keyword: :keyword', ['keyword' => $keyword]);
        }
        if ($parsed['size_bytes'] && $rules['min_size_bytes'] && $parsed['size_bytes'] < $rules['min_size_bytes']) {
            return __('Too small');
        }
        if ($parsed['size_bytes'] && $rules['max_size_bytes'] && $parsed['size_bytes'] > $rules['max_size_bytes']) {
            return __('Too large');
        }

        return null;
    }

    /**
     * Sort keys, most significant first. With no rules set this is exactly
     * the original ranking: cached, resolution desc, seeders, size desc.
     *
     * @param  array<string, mixed>  $rules  normalizeRules() output.
     * @return array<int, \Closure>
     */
    protected function comparators(array $rules): array
    {
        $comparators = [];

        if ($rules['prefer_cached']) {
            $comparators[] = fn (array $a, array $b) => ($b['parsed']['cached'] <=> $a['parsed']['cached']);
        }

        if ($rules['languages'] !== []) {
            $comparators[] = fn (array $a, array $b) => ($this->languageRank($a['parsed']['languages'], $rules['languages']) ?? PHP_INT_MAX)
                <=> ($this->languageRank($b['parsed']['languages'], $rules['languages']) ?? PHP_INT_MAX);
        }

        if ($rules['resolutions'] !== []) {
            // Listed resolutions in the profile's order, then anything else highest first.
            $comparators[] = function (array $a, array $b) use ($rules) {
                $rank = fn (array $c) => array_search($c['parsed']['resolution'], $rules['resolutions'], true);
                $rankA = $rank($a);
                $rankB = $rank($b);

                if ($rankA !== false || $rankB !== false) {
                    return ($rankA === false ? PHP_INT_MAX : $rankA) <=> ($rankB === false ? PHP_INT_MAX : $rankB);
                }

                return ($b['parsed']['resolution'] ?? 0) <=> ($a['parsed']['resolution'] ?? 0);
            };
        } else {
            $comparators[] = fn (array $a, array $b) => (($b['parsed']['resolution'] ?? 0) <=> ($a['parsed']['resolution'] ?? 0));
        }

        if ($rules['preferred_keywords'] !== []) {
            $comparators[] = fn (array $a, array $b) => $this->keywordHits($b['stream'], $rules['preferred_keywords'])
                <=> $this->keywordHits($a['stream'], $rules['preferred_keywords']);
        }

        $comparators[] = fn (array $a, array $b) => (($b['parsed']['seeders'] ?? 0) <=> ($a['parsed']['seeders'] ?? 0));

        $comparators[] = $rules['prefer_smaller']
            ? fn (array $a, array $b) => (($a['parsed']['size_bytes'] ?? PHP_INT_MAX) <=> ($b['parsed']['size_bytes'] ?? PHP_INT_MAX))
            : fn (array $a, array $b) => (($b['parsed']['size_bytes'] ?? 0) <=> ($a['parsed']['size_bytes'] ?? 0));

        return $comparators;
    }

    /**
     * Position of the stream's best audio language in the preferred list, or
     * null if none match. An untagged release counts as 'original'; a
     * multi-audio release carries the original track too.
     *
     * @param  array<int, string>  $streamLanguages
     * @param  array<int, string>  $preferred
     */
    public function languageRank(array $streamLanguages, array $preferred): ?int
    {
        if ($streamLanguages === [] || in_array('multi', $streamLanguages, true)) {
            $streamLanguages[] = 'original';
        }

        foreach ($preferred as $index => $language) {
            if (in_array($language, $streamLanguages, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Searchable text for keyword rules: display text (normalized) plus the
     * release name and AIOStreams' bingeGroup.
     *
     * @param  array<string, mixed>  $stream
     */
    protected function keywordHaystack(array $stream): string
    {
        return self::normalizeText(implode(' ', array_filter([
            $stream['name'] ?? null,
            $stream['title'] ?? null,
            $stream['description'] ?? null,
            $stream['behaviorHints']['filename'] ?? null,
            $stream['behaviorHints']['bingeGroup'] ?? null,
        ], 'is_string')));
    }

    /**
     * Keywords match case-insensitively as whole words (so "TS" doesn't match
     * inside "DTS"), with separators like dots treated as spaces.
     *
     * @param  array<string, mixed>  $stream
     * @param  array<int, string>  $keywords
     */
    protected function firstMatchingKeyword(array $stream, array $keywords): ?string
    {
        if ($keywords === []) {
            return null;
        }

        $haystack = $this->keywordHaystack($stream);

        foreach ($keywords as $keyword) {
            if ($this->containsKeyword($haystack, $keyword)) {
                return $keyword;
            }
        }

        return null;
    }

    protected function containsKeyword(string $haystack, string $keyword): bool
    {
        return (bool) preg_match('/(?<![\p{L}\p{N}])'.preg_quote($keyword, '/').'(?![\p{L}\p{N}])/iu', $haystack);
    }

    /**
     * @param  array<string, mixed>  $stream
     * @param  array<int, string>  $keywords
     */
    protected function keywordHits(array $stream, array $keywords): int
    {
        $haystack = $this->keywordHaystack($stream);

        return collect($keywords)
            ->filter(fn (string $keyword) => $this->containsKeyword($haystack, $keyword))
            ->count();
    }

    /**
     * One-line tag summary for the manual picker / profile tester, e.g.
     * "2160p · Remux · HDR · HEVC · DTS · 5.1 · Audio: multi, fr · 88.2 GB".
     *
     * @param  array<string, mixed>  $parsed
     */
    public static function describe(array $parsed): string
    {
        $sizeGb = ! empty($parsed['size_bytes']) ? round($parsed['size_bytes'] / 1_073_741_824, 1).' GB' : null;
        $languages = $parsed['languages'] ?? [];

        return collect([
            ! empty($parsed['resolution']) ? $parsed['resolution'].'p' : null,
            match ($parsed['source'] ?? null) {
                null => null,
                'web-dl' => 'WEB-DL',
                'webrip' => 'WEBRip',
                'bluray' => 'BluRay',
                'hdtv', 'dvd', 'cam' => strtoupper($parsed['source']),
                default => ucfirst($parsed['source']),
            },
            ! empty($parsed['dv']) ? 'DV' : null,
            ! empty($parsed['hdr']) ? 'HDR' : null,
            ! empty($parsed['codec']) ? strtoupper($parsed['codec']) : null,
            ! empty($parsed['audio_format']) ? strtoupper($parsed['audio_format']) : null,
            $parsed['audio_channels'] ?? null,
            __('Audio: :languages', ['languages' => $languages === [] ? __('untagged') : implode(', ', $languages)]),
            $sizeGb,
            ! empty($parsed['cached']) ? __('Cached') : null,
            $parsed['release_group'] ?? null,
        ])->filter()->implode(' · ');
    }
}
