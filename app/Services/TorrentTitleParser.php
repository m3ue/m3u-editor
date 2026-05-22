<?php

namespace App\Services;

/**
 * Parses torrent/NZB-style filenames and directory names.
 *
 * Handles patterns like:
 *   Show.Name.S01E01.1080p.BluRay.mkv
 *   Show Name - S01E01 - Episode Title [Group].mkv
 *   Movie.Name.2024.2160p.UHD.BluRay.REMUX.mkv
 *   Show Name 2019-2025 [S01-S07] [10Bit HDR][1080p NF WEB-DL H265][Lektor PL][Group]
 */
class TorrentTitleParser
{
    /**
     * Tokens that mark the end of a clean title.
     * Extended-mode pattern — internal whitespace is ignored.
     */
    private const TITLE_BREAK_PATTERN = '/(?:
        # Resolution
        \b(?:4K|UHD|2160p|2160i|1080p|1080i|720p|720i|576p|480p|480i|540p)\b
        # Source
        |\b(?:BluRay|Blu-Ray|BDRip|BRRip|BDRemux|BDREMUX|REMUX|
              WEB-DL|WEBDL|WEBRip|WEB-RIP|
              HDTV|PDTV|DVDRip|DVD-Rip|DVDSCR|VODRip|CAM)\b
        # Codec
        |\b(?:x264|x265|H\.264|H\.265|H264|H265|HEVC|AVC|xvid|divx|AV1)\b
        # Streaming service tags (short uppercase only, unlikely to appear in titles)
        |\b(?:NF|AMZN|ATVP|DSNP|PCOK|STAN)\b
        # Audio
        |\b(?:TrueHD|DTS-HD|DTS-ES|DTS|EAC3|E-AC-3|AC3|AAC|Atmos|DDP)\b
        # HDR and colour volume
        |\b(?:HDR10\+|HDR10|HDR|DoVi|DV)\b
        # Bit depth
        |\b(?:10Bit|10bit|8Bit|8bit|Hi10)\b
        # Release flags
        |\b(?:PROPER|REPACK|INTERNAL|RETAIL|EXTENDED|UNRATED|THEATRICAL|IMAX)\b
        # Multi-language release tags
        |\b(?:MULTi|MULTI|VFI|VFF|VFQ|VF2|VOSTFR|TRUEFRENCH|FRENCH)\b
        # Language release tags
        |\b(?:Lektor|Napisy|Lector|Dubbing)\b
    )/ixu';

    /**
     * Parse a filename or directory name and return structured metadata.
     *
     * @return array{
     *   title: string,
     *   year: int|null,
     *   season: int|null,
     *   episode: int|null,
     *   is_episode: bool,
     *   is_pack: bool
     * }
     */
    public function parse(string $filename): array
    {
        // Strip extension only when it looks like a real file extension (short, alphanumeric).
        // Directory names can contain dots (e.g. www.UIndex.org - …) which pathinfo would
        // otherwise misidentify as an extension and swallow the rest of the string.
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = ($ext !== '' && strlen($ext) <= 5 && ctype_alnum($ext))
            ? pathinfo($filename, PATHINFO_FILENAME)
            : $filename;
        $base = trim($base);

        // Remove torrent-site watermarks before any pattern matching
        $base = $this->stripSiteWatermarks($base);

        // 1 — Multi-season pack: [S01-S07], S01-S07
        if (preg_match('/[\[(]?[Ss](\d{1,2})\s*[-–]\s*[Ss](\d{1,2})[\])]?/u', $base, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'title' => $this->cleanTitle(substr($base, 0, $m[0][1])),
                'year' => $this->extractYear($base),
                'season' => (int) $m[1][0],
                'episode' => null,
                'is_episode' => false,
                'is_pack' => true,
            ];
        }

        // 2 — Season + episode: S01E01, S1E1 (with optional space)
        if (preg_match('/[Ss](\d{1,2})\s?[Ee](\d{1,3})/u', $base, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'title' => $this->cleanTitle(substr($base, 0, $m[0][1])),
                'year' => null,
                'season' => (int) $m[1][0],
                'episode' => (int) $m[2][0],
                'is_episode' => true,
                'is_pack' => false,
            ];
        }

        // 3 — NxNN format: 1x01
        if (preg_match('/\b(\d{1,2})x(\d{2,3})\b/u', $base, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'title' => $this->cleanTitle(substr($base, 0, $m[0][1])),
                'year' => null,
                'season' => (int) $m[1][0],
                'episode' => (int) $m[2][0],
                'is_episode' => true,
                'is_pack' => false,
            ];
        }

        // 4 — Standalone season pack: "Season 2", "S02" not followed by an episode number
        if (preg_match('/\b(?:Season|Saison)\s?(\d{1,2})\b/iu', $base, $m, PREG_OFFSET_CAPTURE) ||
            preg_match('/\bS(\d{1,2})(?![Ee]\d)(?=[\s._\[(\-]|$)/iu', $base, $m, PREG_OFFSET_CAPTURE)) {
            return [
                'title' => $this->cleanTitle(substr($base, 0, $m[0][1])),
                'year' => $this->extractYear($base),
                'season' => (int) $m[1][0],
                'episode' => null,
                'is_episode' => false,
                'is_pack' => true,
            ];
        }

        // 5 — No episode markers → treat as movie/standalone item
        $titleEnd = $this->findTitleBreak($base);

        return [
            'title' => $this->cleanTitle(substr($base, 0, $titleEnd)),
            'year' => $this->extractYear($base),
            'season' => null,
            'episode' => null,
            'is_episode' => false,
            'is_pack' => false,
        ];
    }

    /**
     * Strip common torrent-site watermark prefixes from directory and file names.
     *
     * Handles patterns like:
     *   [XTORRENTY.ORG] Movie Name...
     *   www.UIndex.org    -    Movie Name...
     *   www.SomeSite.com - Movie Name...
     */
    private function stripSiteWatermarks(string $str): string
    {
        // Strip leading [SOMETHING.TLD] bracket (e.g. [XTORRENTY.ORG])
        $str = preg_replace('/^\s*\[[^\]]{1,50}\.[A-Za-z]{2,6}\]\s*/u', '', $str) ?? $str;

        // Strip leading www.domain.tld followed by any separator and dash
        // Handles extra spaces like "www.UIndex.org    -    "
        $str = preg_replace('/^\s*www\.\S+\s*[-–]+\s*/iu', '', $str) ?? $str;

        return trim($str);
    }

    /**
     * Find the character position where quality/source/codec tokens begin.
     * Everything before this position is candidate title text.
     */
    private function findTitleBreak(string $str): int
    {
        $len = mb_strlen($str);
        $min = $len;

        // Year preceded by a delimiter — use last match so title-leading years don't break early
        if (preg_match_all('/(?<=[.\s(\[_-])(?:19|20)\d{2}(?=[.\s)\]_\-]|$)/u', $str, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [, $pos]) {
                if ($pos > 2) {
                    $min = min($min, $pos);
                    break; // first occurrence after position 2 is enough
                }
            }
        }

        // Quality / codec / source tokens
        if (preg_match(self::TITLE_BREAK_PATTERN, $str, $m, PREG_OFFSET_CAPTURE)) {
            $min = min($min, $m[0][1]);
        }

        // Opening bracket that starts a tag block (not part of title)
        // Only use if there's no better match and position is reasonable
        if (preg_match('/[\[({]/u', $str, $m, PREG_OFFSET_CAPTURE) && $m[0][1] > 3) {
            // Check if the bracket content looks like a tag (quality, codec, group name)
            if (preg_match('/[\[({](?:[\w\s.,-]{1,40})[\])}]/u', $str, $bm, PREG_OFFSET_CAPTURE, $m[0][1])) {
                $min = min($min, $bm[0][1]);
            }
        }

        return $min ?: $len;
    }

    /**
     * Extract the most likely year from the string.
     * Uses the first year found after a delimiter (not position 0).
     */
    private function extractYear(string $str): ?int
    {
        if (preg_match_all('/(?<=[.\s(\[_-])((?:19|20)\d{2})(?=[.\s)\]_\-]|$)/u', $str, $matches)) {
            foreach ($matches[1] as $year) {
                $y = (int) $year;
                if ($y >= 1888 && $y <= ((int) date('Y') + 2)) {
                    return $y;
                }
            }
        }

        return null;
    }

    /**
     * Produce a clean, human-readable title from raw pre-break text.
     */
    private function cleanTitle(string $raw): string
    {
        // Strip trailing separators
        $title = rtrim($raw, ' .-_([');

        // Strip leading [TAG] / [SITE.TLD] bracket blocks
        $title = preg_replace('/^\s*\[[^\]]{0,60}\]\s*/u', '', $title) ?? $title;

        // Replace dots used as word separators. Only true decimal points (digit.digit) are kept.
        $title = preg_replace('/(?<!\d)\.|\.(?!\d)/u', ' ', $title) ?? $title;
        $title = str_replace('_', ' ', $title);

        // Strip trailing year ranges like "2019-2025"
        $title = preg_replace('/\s+\d{4}\s*[-–]\s*\d{4}\s*$/u', '', $title) ?? $title;

        // Strip trailing standalone year — years at the end are metadata, not title
        $title = preg_replace('/\s+(?:19|20)\d{2}\s*$/u', '', $title) ?? $title;

        // Strip trailing standalone year in brackets/parens like "(2024)"
        $title = preg_replace('/\s*\(\d{4}\)\s*$/u', '', $title) ?? $title;

        // Remove bracket/paren tag blocks from the end
        $title = preg_replace('/\s*[\[({][^\])}]{0,60}[\])}]\s*$/u', '', $title) ?? $title;

        // Trim dashes used as separators
        $title = trim($title, ' -–');

        // Collapse multiple spaces
        $title = preg_replace('/\s{2,}/u', ' ', $title) ?? $title;

        return trim($title);
    }
}
