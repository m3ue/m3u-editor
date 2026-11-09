<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Searches the documentation index published by the docs site
 * (scripts/build-copilot-index.js in m3u-editor-docs-v2, served at
 * /data/copilot-docs.json) so the Copilot always cites pages that exist.
 */
class CopilotDocsSearch
{
    public const FRESH_CACHE_KEY = 'copilot-docs-index';

    public const STALE_CACHE_KEY = 'copilot-docs-index-stale';

    private const FRESH_TTL_SECONDS = 21600; // 6 hours

    private const STALE_TTL_SECONDS = 2592000; // 30 days, served when the site is unreachable

    private const STOP_WORDS = [
        'a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been', 'has', 'have', 'had', 'do', 'does', 'did',
        'will', 'would', 'could', 'should', 'can', 'may', 'how', 'what', 'where', 'when', 'why', 'which', 'who',
        'to', 'in', 'on', 'at', 'for', 'of', 'with', 'about', 'and', 'or', 'it', 'its', 'this', 'that', 'there',
        'i', 'me', 'my', 'we', 'you', 'your', 'get', 'set', 'use', 'using', 'make', 'work', 'works',
    ];

    public static function indexUrl(): string
    {
        return rtrim((string) config('dev.docs_url'), '/').'/data/copilot-docs.json';
    }

    /**
     * Load the published index, cached for six hours. Falls back to the last
     * good copy if the docs site cannot be reached.
     *
     * @return array<string, mixed>|null
     */
    public function loadIndex(): ?array
    {
        $fresh = Cache::get(self::FRESH_CACHE_KEY);

        if (is_array($fresh)) {
            return $fresh;
        }

        try {
            $response = Http::timeout(8)->acceptJson()->get(self::indexUrl());
            $index = $response->successful() ? $response->json() : null;
        } catch (Throwable) {
            $index = null;
        }

        if (is_array($index) && is_array($index['pages'] ?? null)) {
            Cache::put(self::FRESH_CACHE_KEY, $index, self::FRESH_TTL_SECONDS);
            Cache::put(self::STALE_CACHE_KEY, $index, self::STALE_TTL_SECONDS);

            return $index;
        }

        $stale = Cache::get(self::STALE_CACHE_KEY);

        return is_array($stale) ? $stale : null;
    }

    /**
     * Rank documentation sections against a free-text query.
     *
     * @param  array<string, mixed>  $index
     * @return list<array{page: string, url: string, heading: string, text: string, score: float}>
     */
    public function search(array $index, string $query, int $limit = 4): array
    {
        $terms = $this->terms($query);

        if ($terms === []) {
            return [];
        }

        $entries = [];
        $documentFrequency = array_fill_keys($terms, 0);

        foreach ($index['pages'] ?? [] as $page) {
            $pageMeta = $this->tokenize(implode(' ', [
                $page['title'] ?? '',
                $page['description'] ?? '',
                implode(' ', $page['tags'] ?? []),
                str_replace(['/', '_', '-'], ' ', $page['path'] ?? ''),
            ]));

            foreach ($page['sections'] ?? [] as $section) {
                $headingTokens = $this->tokenize($section['heading'] ?? '');
                $bodyTokens = $this->tokenize($section['text'] ?? '');

                foreach ($terms as $term) {
                    if (isset($pageMeta[$term]) || isset($headingTokens[$term]) || isset($bodyTokens[$term])) {
                        $documentFrequency[$term]++;
                    }
                }

                $entries[] = compact('page', 'section', 'pageMeta', 'headingTokens', 'bodyTokens');
            }
        }

        $sectionCount = max(count($entries), 1);
        $scored = [];

        foreach ($entries as $entry) {
            $score = 0.0;
            $bodyLength = max(array_sum($entry['bodyTokens']), 1);

            foreach ($terms as $term) {
                $inverseFrequency = log(1 + ($sectionCount - $documentFrequency[$term] + 0.5) / ($documentFrequency[$term] + 0.5));
                $bodyCount = $entry['bodyTokens'][$term] ?? 0;
                $bodyScore = $bodyCount > 0 ? ($bodyCount * 2.2) / ($bodyCount + 1.2 * (0.25 + 0.75 * $bodyLength / 150)) : 0;

                $termScore = $bodyScore
                    + (isset($entry['headingTokens'][$term]) ? 3.0 : 0.0)
                    + (isset($entry['pageMeta'][$term]) ? 2.0 : 0.0);

                $score += $inverseFrequency * $termScore;
            }

            if ($score > 0) {
                $scored[] = [
                    'page' => (string) ($entry['page']['title'] ?? ''),
                    'url' => $this->sectionUrl($entry['page'], $entry['section']),
                    'heading' => (string) ($entry['section']['heading'] ?? ''),
                    'text' => (string) ($entry['section']['text'] ?? ''),
                    'score' => $score,
                ];
            }
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    /** @return list<string> */
    private function terms(string $query): array
    {
        $terms = array_keys($this->tokenize($query));

        return array_values(array_filter(
            $terms,
            fn (string $word): bool => strlen($word) > 1 && ! in_array($word, self::STOP_WORDS, true),
        ));
    }

    /**
     * Lowercased, lightly stemmed word counts. Stemming only strips a plural
     * "s" so "playlists" matches "playlist" without mangling product names.
     *
     * @return array<string, int>
     */
    private function tokenize(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $counts = [];

        foreach ($words as $word) {
            if (strlen($word) > 3 && str_ends_with($word, 's') && ! str_ends_with($word, 'ss')) {
                $word = substr($word, 0, -1);
            }

            $counts[$word] = ($counts[$word] ?? 0) + 1;
        }

        return $counts;
    }

    /**
     * @param  array<string, mixed>  $page
     * @param  array<string, mixed>  $section
     */
    private function sectionUrl(array $page, array $section): string
    {
        $anchor = (string) ($section['anchor'] ?? '');

        return (string) ($page['url'] ?? '').($anchor !== '' ? '#'.$anchor : '');
    }
}
