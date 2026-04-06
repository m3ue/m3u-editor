<?php

declare(strict_types=1);

namespace App\Filament\CopilotTools;

use EslamRedaDiv\FilamentCopilot\Tools\BaseTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Copilot tool that searches the m3u editor documentation at
 * https://m3ue.sparkison.dev/docs.
 *
 * It scores all known doc slugs by keyword relevance, then fetches and
 * returns the full text content of the top matching pages via HTTP.
 */
class SearchDocsTool extends BaseTool
{
    private const DOC_SLUGS = [
        'intro',
        'installation',
        'quick-start',
        'configuration',
        'client-configuration',
        'troubleshooting',
        'advanced/auto-merge-channels',
        'advanced/environment-variables',
        'advanced/epg-optimization',
        'advanced/playlist-pooled-providers',
        'advanced/settings-reference',
        'advanced/sso-oidc',
        'advanced/stream-probing',
        'advanced/strm-files',
        'proxy/overview',
        'proxy/api-reference',
        'proxy/authentication',
        'proxy/configuration',
        'proxy/event-system',
        'proxy/failover',
        'proxy/hardware-acceleration',
        'proxy/redis-pooling',
        'proxy/retry',
        'proxy/silence-detection',
        'proxy/sticky-sessions',
        'proxy/stream-metadata',
        'proxy/strict-live-ts',
        'proxy/transcoding',
        'resources/custom-playlist',
        'resources/epg-setup',
        'resources/merged-playlist',
        'resources/playlist-alias',
        'resources/playlist-auth',
        'resources/playlists',
        'resources/xtream-dns-failover',
    ];

    private const DOCS_BASE_URL = 'https://m3ue.sparkison.dev/docs';

    private const MAX_RESULTS = 3;

    private const EXCERPT_LENGTH = 800;

    public function description(): Stringable|string
    {
        return 'Search the m3u editor documentation for information about features, configuration, and usage. Use this when the user asks how something works, how to set something up, or what a feature does.';
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Search term or question to look up in the docs')->required(),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) $request['query']);

        if ($query === '') {
            return 'Please provide a search query.';
        }

        $keywords = $this->extractKeywords($query);

        // Score each slug by how many keywords appear in its path words
        $scored = [];

        foreach (self::DOC_SLUGS as $slug) {
            $slugWords = str_replace(['/', '-', '_'], ' ', $slug);
            $score = $this->score($slugWords, $keywords);

            if ($score > 0) {
                $scored[] = ['slug' => $slug, 'score' => $score];
            }
        }

        // If nothing matched on slug alone, include all pages so we can
        // search by fetched content below (up to MAX_RESULTS)
        if (empty($scored)) {
            foreach (self::DOC_SLUGS as $slug) {
                $scored[] = ['slug' => $slug, 'score' => 0];
            }
        }

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        $lines = ["Documentation results for '{$query}':", ''];
        $found = 0;

        foreach ($scored as $candidate) {
            if ($found >= self::MAX_RESULTS) {
                break;
            }

            $url = self::DOCS_BASE_URL.'/'.$candidate['slug'];
            $page = $this->fetchPage($url, $keywords);

            if ($page === null) {
                continue;
            }

            // Re-score against full page content when slug scoring was zero
            if ($candidate['score'] === 0 && $this->score($page['text'], $keywords) === 0) {
                continue;
            }

            $lines[] = '## '.$page['title'];
            $lines[] = 'URL: '.$url;
            $lines[] = '';
            $lines[] = $page['excerpt'];
            $lines[] = '';
            $found++;
        }

        if ($found === 0) {
            return "No documentation found matching '{$query}'. Browse the docs at ".self::DOCS_BASE_URL;
        }

        return implode("\n", $lines);
    }

    /**
     * Fetch a docs page and return its title, full text, and a keyword excerpt.
     *
     * @return array{title: string, text: string, excerpt: string}|null
     */
    private function fetchPage(string $url, array $keywords): ?array
    {
        try {
            $response = Http::timeout(6)->get($url);

            if (! $response->ok()) {
                return null;
            }

            $html = $response->body();

            // Title from <h1>
            preg_match('/<h1[^>]*>(.*?)<\/h1>/si', $html, $titleMatch);
            $title = strip_tags($titleMatch[1] ?? basename($url));

            // Main content from <article>, falling back to <main>
            preg_match('/<article[^>]*>(.*?)<\/article>/si', $html, $articleMatch);

            if (empty($articleMatch[1])) {
                preg_match('/<main[^>]*>(.*?)<\/main>/si', $html, $articleMatch);
            }

            $raw = $articleMatch[1] ?? $html;
            $raw = preg_replace('/<(script|style|nav|header|footer)[^>]*>.*?<\/\1>/si', '', $raw) ?? $raw;
            $text = strip_tags($raw);
            $text = preg_replace('/\s+/', ' ', $text) ?? $text;
            $text = trim($text);

            $excerpt = $this->excerptAround($text, $keywords);

            return ['title' => trim($title), 'text' => $text, 'excerpt' => $excerpt];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Return a context window around the first keyword hit. */
    private function excerptAround(string $text, array $keywords): string
    {
        $lower = strtolower($text);

        foreach ($keywords as $keyword) {
            $pos = strpos($lower, $keyword);

            if ($pos !== false) {
                $start = max(0, $pos - 200);
                $excerpt = mb_substr($text, $start, self::EXCERPT_LENGTH);

                return ($start > 0 ? '...' : '').$excerpt.'...';
            }
        }

        return mb_substr($text, 0, self::EXCERPT_LENGTH).'...';
    }

    /** @return list<string> */
    private function extractKeywords(string $query): array
    {
        $stopWords = ['a', 'an', 'the', 'is', 'are', 'was', 'were', 'be', 'been',
            'has', 'have', 'had', 'do', 'does', 'did', 'will', 'would', 'could',
            'should', 'can', 'may', 'how', 'what', 'where', 'when', 'why', 'to',
            'in', 'on', 'at', 'for', 'of', 'with', 'about', 'i', 'me', 'my'];

        $words = preg_split('/\W+/', strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($words, fn ($w) => strlen($w) > 2 && ! in_array($w, $stopWords)));
    }

    private function score(string $text, array $keywords): int
    {
        $text = strtolower($text);
        $score = 0;

        foreach ($keywords as $keyword) {
            $score += substr_count($text, $keyword);
        }

        return $score;
    }
}
