<?php

namespace App\Support;

/**
 * EpisodeNumberParser — shared utility for parsing XMLTV episode_num strings.
 *
 * Two entry points:
 *
 *   fromRaw()       — heuristic parse of a plain episode_num string.
 *                     Used when only the raw string is available (e.g. stored
 *                     on a DvrRecording via epg_programme_data.episode_num).
 *
 *   fromProgramme() — full priority parse with explicit system-tag awareness.
 *                     Used during EPG import where the episode_nums array
 *                     (with system="xmltv_ns" / system="onscreen" tags) is present.
 */
final class EpisodeNumberParser
{
    /**
     * Heuristic parse from a plain episode_num string.
     *
     * Handles two common formats:
     *   - Dot notation "1.2." → xmltv_ns 0-indexed → season 2, episode 3
     *   - SxxExx string "S01E03" → season 1, episode 3
     *
     * Important: many providers (Schedules Direct, Gracenote, Zap2it, etc.)
     * stuff non-XMLTV identifiers into <episode-num> with a single dot
     * (e.g. "EP012345.0001", "SH123.456", "MV000123.0000", "tt12345.0").
     * Those are channel-EPG IDs, NOT season/episode numbers, and must NOT
     * be parsed as xmltv_ns. We therefore require the strict xmltv_ns shape:
     *
     *   <int|empty>[/total] . <int|empty>[/total] . <int|empty>[/total]
     *
     * (At least the first two dot-separated segments must each be either
     *  empty or a pure integer, optionally followed by "/N".)
     *
     * @return array{0: int|null, 1: int|null} [season, episode]
     */
    public static function fromRaw(?string $raw): array
    {
        if ($raw === null) {
            return [null, null];
        }

        $raw = trim($raw);
        if ($raw === '') {
            return [null, null];
        }

        // SxxExx is unambiguous — try it first.
        if (preg_match('/S(\d+)E(\d+)/i', $raw, $m)) {
            return [min(32767, (int) $m[1]), min(32767, (int) $m[2])];
        }

        // Strict xmltv_ns: each segment must be empty or an integer with
        // optional "/total". Reject anything containing letters (e.g.
        // "EP012345.0001", "tt12345.0") or non-numeric tokens.
        if (str_contains($raw, '.')) {
            $segmentRegex = '/^\s*(\d*)(?:\s*\/\s*\d+)?\s*$/';
            $parts = explode('.', $raw);

            // Need at least season and episode segments.
            if (count($parts) < 2) {
                return [null, null];
            }

            $seasonRaw = $parts[0];
            $episodeRaw = $parts[1];

            if (! preg_match($segmentRegex, $seasonRaw, $sm) || ! preg_match($segmentRegex, $episodeRaw, $em)) {
                return [null, null];
            }

            // Optional "part" segment (parts[2]) — also validate when present
            // so strings like "1.2.foo" don't sneak through.
            if (isset($parts[2]) && ! preg_match($segmentRegex, $parts[2])) {
                return [null, null];
            }

            $season = ($sm[1] !== '') ? min(32767, (int) $sm[1] + 1) : null;
            $episode = ($em[1] !== '') ? min(32767, (int) $em[1] + 1) : null;

            return [$season, $episode];
        }

        return [null, null];
    }

    /**
     * Full-priority parse from a programme payload that includes the episode_nums array.
     *
     * Priority order:
     *   1. Explicit system="xmltv_ns" tag  — 0-indexed dot notation ("S.E.P")
     *   2. Explicit system="onscreen" tag  — 1-indexed SxxExx literal
     *   3. Heuristic fallback on raw episode_num string (delegates to fromRaw)
     *
     * @param  array{episode_num: string, episode_nums: list<array{system: string, value: string}>}  $programme
     * @return array{0: int|null, 1: int|null} [season, episode]
     */
    public static function fromProgramme(array $programme): array
    {
        $season = null;
        $episode = null;
        $episodeNums = $programme['episode_nums'] ?? [];

        // Priority 1: explicit xmltv_ns system (0-indexed dot notation).
        // We still validate the shape strictly — some providers tag
        // dd_progid-style IDs with system="xmltv_ns" by mistake.
        $segmentRegex = '/^\s*(\d*)(?:\s*\/\s*\d+)?\s*$/';
        foreach ($episodeNums as $en) {
            if (strtolower($en['system']) !== 'xmltv_ns') {
                continue;
            }

            $value = trim((string) ($en['value'] ?? ''));
            if (! str_contains($value, '.')) {
                continue;
            }

            $parts = explode('.', $value);
            if (count($parts) < 2) {
                continue;
            }

            if (! preg_match($segmentRegex, $parts[0], $sm) || ! preg_match($segmentRegex, $parts[1], $em)) {
                continue;
            }

            if (isset($parts[2]) && ! preg_match($segmentRegex, $parts[2])) {
                continue;
            }

            if ($sm[1] !== '') {
                $season = min(32767, (int) $sm[1] + 1);
            }

            if ($em[1] !== '') {
                $episode = min(32767, (int) $em[1] + 1);
            }

            break; // first valid xmltv_ns entry wins
        }

        // Priority 2: explicit onscreen system (1-indexed SxxExx)
        if ($season === null && $episode === null) {
            foreach ($episodeNums as $en) {
                if (strtolower($en['system']) === 'onscreen' && preg_match('/S(\d+)E(\d+)/i', $en['value'], $m)) {
                    $season = min(32767, (int) $m[1]);
                    $episode = min(32767, (int) $m[2]);
                    break;
                }
            }
        }

        // Priority 3: heuristic on raw episode_num string
        if ($season === null && $episode === null) {
            return self::fromRaw($programme['episode_num'] ?? null);
        }

        return [$season, $episode];
    }

    /**
     * Parse season/episode + optional episode title from an EPG description.
     *
     * Some XMLTV providers omit <episode-num> entirely and instead embed the
     * S/E + episode title at the start of <desc>:
     *
     *   "S01 E06 Landfall\nAfter having a breakthrough..."
     *   "S2E07: The Reckoning. Synopsis goes here."
     *   "1x06 - Landfall — synopsis..."
     *   "Season 1 Episode 6: Landfall. Synopsis..."
     *   "Episode 6: Landfall. Synopsis..."   (season-less)
     *
     * Rules:
     *   - Match must be **anchored** at the start of the trimmed description.
     *     "Episode 6 of the season" mid-sentence does NOT match (avoids false
     *     positives like "6 things you didn't know...").
     *   - Episode title is whatever follows the S/E token up to the first
     *     newline, period (.), em-dash (— —), or end of string.
     *     Trimmed; must be at least 2 chars and not start with a lowercase
     *     letter (lowercase is more likely a continuation of the synopsis).
     *   - Returns [null, null, null] when no anchored S/E pattern matches.
     *
     * @return array{0: int|null, 1: int|null, 2: string|null} [season, episode, episodeTitle]
     */
    public static function fromDescription(?string $description): array
    {
        if ($description === null) {
            return [null, null, null];
        }

        $desc = ltrim($description);
        if ($desc === '') {
            return [null, null, null];
        }

        $patterns = [
            // S01 E06, S01E06, s1e6 — optional space, optional separator before title
            '/^S(?<s>\d{1,3})\s*E(?<e>\d{1,3})\b\s*[:.\-\x{2013}\x{2014}]?\s*(?<title>[^\r\n.\x{2013}\x{2014}]*)/iu',
            // 1x06, 01x6 — Plex/Trakt style
            '/^(?<s>\d{1,3})x(?<e>\d{1,3})\b\s*[:.\-\x{2013}\x{2014}]?\s*(?<title>[^\r\n.\x{2013}\x{2014}]*)/iu',
            // "Season 1 Episode 6"
            '/^Season\s+(?<s>\d{1,3})\s*[,:]?\s+Episode\s+(?<e>\d{1,3})\b\s*[:.\-\x{2013}\x{2014}]?\s*(?<title>[^\r\n.\x{2013}\x{2014}]*)/iu',
            // "Episode 6" / "Ep 6" / "Ep. 6" — season-less
            '/^Ep(?:isode)?\.?\s+(?<e>\d{1,3})\b\s*[:.\-\x{2013}\x{2014}]?\s*(?<title>[^\r\n.\x{2013}\x{2014}]*)/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $desc, $m)) {
                $season = isset($m['s']) && $m['s'] !== '' ? min(32767, (int) $m['s']) : null;
                $episode = isset($m['e']) && $m['e'] !== '' ? min(32767, (int) $m['e']) : null;

                $rawTitle = trim($m['title'] ?? '');
                $title = self::sanitiseEpisodeTitle($rawTitle);

                return [$season, $episode, $title];
            }
        }

        return [null, null, null];
    }

    /**
     * Validate a candidate episode title pulled from a description prefix.
     *
     * Rejects strings that are likely continuations of the synopsis rather than
     * an actual episode title (too short, leading lowercase, ends with a
     * sentence verb, etc.).
     */
    private static function sanitiseEpisodeTitle(string $title): ?string
    {
        if ($title === '' || mb_strlen($title) < 2 || mb_strlen($title) > 120) {
            return null;
        }

        // Reject when the candidate looks like a sentence continuation.
        // Episode titles typically start uppercase or with a digit/quote.
        $firstChar = mb_substr($title, 0, 1);
        if (mb_strtolower($firstChar) === $firstChar && ! preg_match('/^[\d"\'\(]/', $firstChar)) {
            return null;
        }

        return $title;
    }
}
