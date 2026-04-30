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
class EpisodeNumberParser
{
    /**
     * Heuristic parse from a plain episode_num string.
     *
     * Handles two common formats:
     *   - Dot notation  "1.2." → xmltv_ns 0-indexed → season 2, episode 3
     *   - SxxExx string "S01E03"               → season 1, episode 3
     *
     * @return array{0: int|null, 1: int|null} [season, episode]
     */
    public static function fromRaw(?string $raw): array
    {
        if (empty($raw)) {
            return [null, null];
        }

        if (str_contains($raw, '.')) {
            // Dot notation → treat as xmltv_ns (0-indexed)
            $parts = explode('.', $raw);
            $season = isset($parts[0]) && is_numeric(trim($parts[0]))
                ? min(32767, (int) trim($parts[0]) + 1)
                : null;
            $episode = isset($parts[1]) && is_numeric(trim($parts[1]))
                ? min(32767, (int) trim($parts[1]) + 1)
                : null;

            return [$season, $episode];
        }

        if (preg_match('/S(\d+)E(\d+)/i', $raw, $m)) {
            return [min(32767, (int) $m[1]), min(32767, (int) $m[2])];
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

        // Priority 1: explicit xmltv_ns system (0-indexed dot notation)
        foreach ($episodeNums as $en) {
            if (strtolower($en['system']) === 'xmltv_ns') {
                $parts = explode('.', $en['value']);
                if (isset($parts[0]) && is_numeric(trim($parts[0]))) {
                    $season = min(32767, (int) trim($parts[0]) + 1);
                }
                if (isset($parts[1]) && is_numeric(trim($parts[1]))) {
                    $episode = min(32767, (int) trim($parts[1]) + 1);
                }
                break; // xmltv_ns is authoritative
            }
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
}
