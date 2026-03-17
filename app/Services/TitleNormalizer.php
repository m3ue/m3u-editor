<?php

namespace App\Services;

/**
 * Normalizes VOD/Series titles for cross-provider matching.
 *
 * Handles provider prefixes, quality tags, year suffixes, and
 * common formatting differences to produce a clean title for comparison.
 *
 * @phpstan-type NormalizedResult array{title: string, year: int|null}
 */
class TitleNormalizer
{
    /**
     * Common provider/quality prefixes that appear before a separator.
     * These are stripped when they occur before |, -, or : separators.
     *
     * @var array<int, string>
     */
    protected const QUALITY_TAGS = [
        '4k', 'uhd', '8k', 'hd', 'fhd', 'sd',
        '1080p', '1080i', '720p', '2160p', '4320p',
        'hevc', 'h264', 'h265', 'hdr', 'hdr10', 'dolby',
        'dv', 'atmos', 'multi', 'dual', 'remux', 'bluray',
        'webrip', 'webdl', 'bdrip', 'dvdrip',
        'aac', 'ac3', 'dts', 'flac', 'truehd',
        'x264', 'x265', 'av1', 'vp9',
        'imax', '3d', 'extended', 'unrated', 'directors',
    ];

    /**
     * Separator patterns used by providers to prefix titles.
     * Ordered by specificity: pipe, dash variants, colon.
     *
     * @var array<int, string>
     */
    protected const PREFIX_SEPARATORS = ['|', ' - ', ': ', ' – ', ' — '];

    /**
     * Common suffixes that indicate language/version but are not part of the title.
     *
     * @var array<int, string>
     */
    protected const VERSION_SUFFIXES = [
        'dubbed', 'subbed', 'sub', 'dub',
        'eng', 'english', 'french', 'german', 'spanish', 'italian',
        'danish', 'swedish', 'norwegian', 'finnish', 'dutch',
        'portuguese', 'russian', 'japanese', 'korean', 'chinese',
        'arabic', 'turkish', 'hindi', 'thai', 'polish', 'czech',
        'hungarian', 'romanian', 'greek', 'bulgarian', 'croatian',
        'original', 'ov', 'vf', 'vo', 'vost', 'vostfr',
    ];

    /**
     * Normalize a title for comparison purposes.
     *
     * Strips provider prefixes, quality tags, year suffixes,
     * version suffixes, and normalizes whitespace/case.
     *
     * @return NormalizedResult
     */
    public function normalize(string $title): array
    {
        $year = $this->extractYear($title);
        $cleaned = $this->stripYear($title);
        $cleaned = $this->stripProviderPrefix($cleaned);
        $cleaned = $this->stripSeasonEpisodeTag($cleaned);
        $cleaned = $this->stripQualityTags($cleaned);
        $cleaned = $this->stripVersionSuffix($cleaned);
        $cleaned = $this->stripBracketedContent($cleaned);
        $cleaned = $this->cleanWhitespace($cleaned);
        $cleaned = mb_strtolower($cleaned, 'UTF-8');
        $cleaned = trim($cleaned);

        return [
            'title' => $cleaned,
            'year' => $year,
        ];
    }

    /**
     * Calculate the similarity between two titles as a percentage (0-100).
     *
     * Uses a combination of normalized Levenshtein distance and
     * PHP's similar_text for more robust matching.
     */
    public function similarity(string $titleA, string $titleB): float
    {
        $normA = $this->normalize($titleA);
        $normB = $this->normalize($titleB);

        $a = $normA['title'];
        $b = $normB['title'];

        if ($a === '' || $b === '') {
            return 0.0;
        }

        if ($a === $b) {
            $score = 100.0;
        } else {
            $maxLen = max(mb_strlen($a, 'UTF-8'), mb_strlen($b, 'UTF-8'));

            $levenshtein = levenshtein($a, $b);
            $levenshteinPct = (1 - ($levenshtein / $maxLen)) * 100;

            similar_text($a, $b, $similarPct);

            $score = ($levenshteinPct * 0.6) + ($similarPct * 0.4);
        }

        if ($normA['year'] !== null && $normB['year'] !== null) {
            if ($normA['year'] === $normB['year']) {
                $score = min(100.0, $score + 5.0);
            } else {
                $score = max(0.0, $score - 15.0);
            }
        }

        return round($score, 2);
    }

    /**
     * Group an array of titles by similarity.
     *
     * Returns clusters where each cluster key is the first title encountered,
     * and the value is an array of all titles in that cluster.
     *
     * @param  array<int, array{id: int|string, title: string}>  $items
     * @return array<string, array<int, array{id: int|string, title: string, normalized: string}>>
     */
    public function groupBySimilarity(array $items, float $threshold = 80.0): array
    {
        $groups = [];
        $normalized = [];

        foreach ($items as $item) {
            $norm = $this->normalize($item['title']);
            $normalized[] = [
                ...$item,
                'normalized' => $norm['title'],
                'year' => $norm['year'],
            ];
        }

        $assigned = [];

        foreach ($normalized as $i => $itemA) {
            if (isset($assigned[$i])) {
                continue;
            }

            $groupKey = $itemA['normalized'];
            $groups[$groupKey] = [$itemA];
            $assigned[$i] = true;

            foreach ($normalized as $j => $itemB) {
                if ($i === $j || isset($assigned[$j])) {
                    continue;
                }

                $sim = $this->similarity($itemA['title'], $itemB['title']);
                if ($sim >= $threshold) {
                    $groups[$groupKey][] = $itemB;
                    $assigned[$j] = true;
                }
            }
        }

        return $groups;
    }

    /**
     * Extract a 4-digit year from the title.
     */
    protected function extractYear(string $title): ?int
    {
        if (preg_match('/\((\d{4})\)/', $title, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/[\-–—]\s*(\d{4})\s*$/', $title, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Remove year patterns from the title.
     */
    protected function stripYear(string $title): string
    {
        $title = preg_replace('/\s*\(\d{4}\)\s*/', ' ', $title);
        $title = preg_replace('/\s*[\-–—]\s*\d{4}\s*$/', '', $title);

        return $title;
    }

    /**
     * Strip provider prefix from the title.
     *
     * Handles patterns like:
     * - "DK | The Last Viking"
     * - "4K-SC - The Last Viking"
     * - "SC - The Last Viking"
     * - "DK: Den sidste viking"
     */
    protected function stripProviderPrefix(string $title): string
    {
        foreach (self::PREFIX_SEPARATORS as $separator) {
            $pos = mb_strpos($title, $separator, 0, 'UTF-8');
            if ($pos !== false) {
                $prefix = mb_substr($title, 0, $pos, 'UTF-8');
                $remainder = mb_substr($title, $pos + mb_strlen($separator, 'UTF-8'), null, 'UTF-8');

                if ($this->isProviderPrefix($prefix) && mb_strlen(trim($remainder), 'UTF-8') >= 2) {
                    return trim($remainder);
                }
            }
        }

        return $title;
    }

    /**
     * Determine if a string looks like a provider prefix rather than part of the title.
     *
     * Provider prefixes are typically short (≤20 chars) and may contain
     * quality tags, country codes, or provider abbreviations.
     */
    protected function isProviderPrefix(string $prefix): bool
    {
        $prefix = trim($prefix);

        if (mb_strlen($prefix, 'UTF-8') > 20) {
            return false;
        }

        if (mb_strlen($prefix, 'UTF-8') <= 5) {
            return true;
        }

        $lower = mb_strtolower($prefix, 'UTF-8');
        $parts = preg_split('/[\s\-_]+/', $lower);

        foreach ($parts as $part) {
            if (in_array($part, self::QUALITY_TAGS, true)) {
                return true;
            }
        }

        if (preg_match('/^[a-z]{2,4}$/i', $prefix)) {
            return true;
        }

        if (preg_match('/^[A-Z]{2,5}[\-_]?[A-Z]{0,3}$/i', $prefix)) {
            return true;
        }

        return false;
    }

    /**
     * Remove standalone quality tags from a title.
     */
    protected function stripQualityTags(string $title): string
    {
        $lower = mb_strtolower($title, 'UTF-8');
        $tokens = preg_split('/\s+/', $lower);
        $filtered = [];

        foreach ($tokens as $token) {
            $cleanToken = preg_replace('/[^\p{L}\p{N}]/u', '', $token);
            if (! in_array($cleanToken, self::QUALITY_TAGS, true)) {
                $filtered[] = $token;
            }
        }

        return implode(' ', $filtered);
    }

    /**
     * Strip season/episode tags like S01E05, S01, etc.
     */
    protected function stripSeasonEpisodeTag(string $title): string
    {
        $title = preg_replace('/\b[Ss]\d{1,2}[Ee]\d{1,3}\b/', '', $title);
        $title = preg_replace('/\b[Ss]\d{1,2}\b/', '', $title);

        return $title;
    }

    /**
     * Strip language/version suffixes that appear at the end of titles.
     */
    protected function stripVersionSuffix(string $title): string
    {
        $lower = mb_strtolower(trim($title), 'UTF-8');
        $tokens = preg_split('/\s+/', $lower);
        $titleTokens = preg_split('/\s+/', trim($title));

        while (count($tokens) > 1) {
            $last = end($tokens);
            $cleanLast = preg_replace('/[^\p{L}\p{N}]/u', '', $last);
            if (in_array($cleanLast, self::VERSION_SUFFIXES, true)) {
                array_pop($tokens);
                array_pop($titleTokens);
            } else {
                break;
            }
        }

        return implode(' ', $titleTokens);
    }

    /**
     * Strip content in brackets/parentheses that contains non-title info.
     *
     * Removes patterns like [HD], [Multi], (ENG), etc.
     * Preserves parenthesized content that looks like part of the title.
     */
    protected function stripBracketedContent(string $title): string
    {
        $title = preg_replace('/\[([^\]]*)\]/', '', $title);

        $title = preg_replace_callback('/\(([^)]*)\)/', function ($matches) {
            $content = mb_strtolower(trim($matches[1]), 'UTF-8');
            $tokens = preg_split('/[\s,]+/', $content);
            foreach ($tokens as $token) {
                $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $token);
                if (in_array($clean, self::QUALITY_TAGS, true) || in_array($clean, self::VERSION_SUFFIXES, true)) {
                    return '';
                }
            }

            if (preg_match('/^\d{4}$/', $content)) {
                return '';
            }

            return $matches[0];
        }, $title);

        return $title;
    }

    /**
     * Clean up whitespace and minor formatting.
     */
    protected function cleanWhitespace(string $title): string
    {
        $title = preg_replace('/\s*[\-–—]\s*$/', '', $title);
        $title = preg_replace('/\s+/', ' ', $title);
        $title = preg_replace('/[^\p{L}\p{N}\s]/u', '', $title);

        return trim($title);
    }
}
