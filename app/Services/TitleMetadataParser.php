<?php

namespace App\Services;

/**
 * Extract quality / HDR / audio hints from raw release titles like
 * "Movie.Name.2021.2160p.UHD.BluRay.x265.HDR.DV.Atmos-GROUP".
 *
 * Used as a fallback when no probed stream stats are available.
 */
class TitleMetadataParser
{
    /**
     * Detect a quality string like "2160p", "1080p", "720p", "4K".
     * Returns "" when nothing matches.
     */
    public static function detectQuality(string $title): string
    {
        if ($title === '') {
            return '';
        }

        $haystack = ' '.preg_replace('/[._\-\[\]\(\)]+/', ' ', $title).' ';

        // Explicit resolutions first (most reliable)
        if (preg_match('/\b(2160p|1440p|1080p|720p|576p|480p|360p|240p)\b/i', $haystack, $m)) {
            return strtolower($m[1]);
        }

        // 4K / UHD ⇒ 2160p
        if (preg_match('/\b(4k|uhd)\b/i', $haystack)) {
            return '2160p';
        }

        // FHD / FullHD
        if (preg_match('/\b(fhd|fullhd|full\s?hd)\b/i', $haystack)) {
            return '1080p';
        }

        // HD ⇒ 720p
        if (preg_match('/\b(hd|hdtv)\b/i', $haystack)) {
            return '720p';
        }

        // SD ⇒ 480p
        if (preg_match('/\bsd\b/i', $haystack)) {
            return '480p';
        }

        return '';
    }

    /**
     * Detect HDR / DV markers from title; returns space-joined tokens
     * (e.g. "HDR DV") or "" if none.
     */
    public static function detectHdr(string $title): string
    {
        if ($title === '') {
            return '';
        }

        $haystack = ' '.preg_replace('/[._\-\[\]\(\)]+/', ' ', $title).' ';
        $tokens = [];

        if (preg_match('/\b(dolby\s?vision|dovi|dv)\b/i', $haystack)) {
            $tokens[] = 'DV';
        }

        if (preg_match('/\bhdr10\+\b/i', $haystack)) {
            $tokens[] = 'HDR10+';
        } elseif (preg_match('/\bhdr10\b/i', $haystack)) {
            $tokens[] = 'HDR10';
        } elseif (preg_match('/\bhdr\b/i', $haystack)) {
            $tokens[] = 'HDR';
        }

        return implode(' ', $tokens);
    }

    /**
     * Detect audio info like "Atmos", "TrueHD", "DTS-HD", "DTS-X",
     * "DD+", "AC3", and channel layouts like "5.1" / "7.1".
     */
    public static function detectAudio(string $title): string
    {
        if ($title === '') {
            return '';
        }

        $haystack = ' '.preg_replace('/[._\-\[\]\(\)]+/', ' ', $title).' ';
        $tokens = [];

        // Codecs / formats (priority order)
        if (preg_match('/\batmos\b/i', $haystack)) {
            $tokens[] = 'Atmos';
        }
        if (preg_match('/\btruehd\b/i', $haystack)) {
            $tokens[] = 'TrueHD';
        }
        if (preg_match('/\bdts[\s\-]?hd[\s\-]?ma\b/i', $haystack)) {
            $tokens[] = 'DTS-HD MA';
        } elseif (preg_match('/\bdts[\s\-]?hd\b/i', $haystack)) {
            $tokens[] = 'DTS-HD';
        } elseif (preg_match('/\bdts[\s\-]?x\b/i', $haystack)) {
            $tokens[] = 'DTS-X';
        } elseif (preg_match('/\bdts\b/i', $haystack)) {
            $tokens[] = 'DTS';
        }
        if (preg_match('/\bddp\b|\bdd\+\b|\beac3\b|\be-ac-?3\b/i', $haystack)) {
            $tokens[] = 'DDP';
        } elseif (preg_match('/\bdd\b|\bac-?3\b/i', $haystack)) {
            $tokens[] = 'AC3';
        }
        if (preg_match('/\baac\b/i', $haystack) && empty($tokens)) {
            $tokens[] = 'AAC';
        }

        // Channel layout (only append if we detected a codec or there's a clear hint)
        if (preg_match('/\b([2578])\.([01])\b/', $haystack, $m)) {
            $tokens[] = $m[1].'.'.$m[2];
        }

        return implode(' ', $tokens);
    }
}
