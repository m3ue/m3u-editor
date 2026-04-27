<?php

namespace App\Services;

use App\Models\Series;
use App\Settings\GeneralSettings;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ShowMetadataService
{
    private const TMDB_IMAGE_BASE = 'https://image.tmdb.org/t/p/w342';

    private const TVMAZE_BASE_URL = 'https://api.tvmaze.com';

    private const CACHE_TTL_SECONDS = 86400; // 24 hours

    private const TVMAZE_EPS_CACHE_TTL_SECONDS = 432000; // 5 days

    /**
     * @param  array<string>  $titles
     * @return array<string, string|null>
     */
    public function resolvePosters(array $titles): array
    {
        $titles = array_unique(array_filter($titles, fn ($t) => is_string($t) && $t !== ''));

        if (empty($titles)) {
            return [];
        }

        $results = array_fill_keys($titles, null);
        $remaining = array_flip($titles);

        $this->resolveFromSeriesTable($results, $remaining);
        $this->resolveFromTmdb($results, $remaining);

        if (! empty($remaining)) {
            $this->resolveFromTvMaze($results, $remaining);
        }

        return $results;
    }

    /**
     * Look up poster covers from the local Series table.
     *
     * @param  array<string, string|null>  $results
     * @param  array<string, true>  $remaining
     */
    private function resolveFromSeriesTable(array &$results, array &$remaining): void
    {
        $pendingTitles = array_keys($remaining);
        $series = Series::whereIn('name', $pendingTitles)
            ->whereNotNull('cover')
            ->where('cover', '!=', '')
            ->get(['name', 'cover']);

        foreach ($series as $row) {
            $title = (string) $row->name;
            if (isset($remaining[$title])) {
                $results[$title] = $row->cover;
                unset($remaining[$title]);
            }
        }
    }

    /**
     * Resolve posters via TMDB API (requires API key in GeneralSettings).
     *
     * @param  array<string, string|null>  $results
     * @param  array<string, true>  $remaining
     */
    private function resolveFromTmdb(array &$results, array &$remaining): void
    {
        $apiKey = app(GeneralSettings::class)->tmdb_api_key;

        if (empty($apiKey)) {
            return;
        }

        $titles = array_keys($remaining);
        $pending = $this->filterCached($titles, fn (string $title) => 'showmeta.tmdb.'.md5($title));

        foreach ($pending['hits'] as $title => $url) {
            if ($url !== null) {
                $results[$title] = $url;
                unset($remaining[$title]);
            }
        }

        if (empty($pending['misses'])) {
            return;
        }

        // TV search — concurrent
        $tvResponses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $title) => $pool->as(md5($title))
                ->timeout(10)
                ->get('https://api.themoviedb.org/3/search/tv', [
                    'api_key' => $apiKey,
                    'query' => $title,
                ]),
            $pending['misses'],
        ));

        $tvUrls = $this->parseTmdbTvResponses($tvResponses, $pending['misses']);
        $stillMissing = [];

        foreach ($pending['misses'] as $title) {
            if (isset($tvUrls[$title])) {
                $results[$title] = $tvUrls[$title];
                unset($remaining[$title]);
                Cache::put('showmeta.tmdb.'.md5($title), $tvUrls[$title], self::CACHE_TTL_SECONDS);
            } else {
                $stillMissing[] = $title;
            }
        }

        if (empty($stillMissing)) {
            return;
        }

        // Movie search — concurrent (fallback)
        $movieResponses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $title) => $pool->as(md5($title))
                ->timeout(10)
                ->get('https://api.themoviedb.org/3/search/movie', [
                    'api_key' => $apiKey,
                    'query' => $title,
                ]),
            $stillMissing,
        ));

        $movieUrls = $this->parseTmdbMovieResponses($movieResponses, $stillMissing);

        foreach ($stillMissing as $title) {
            $url = $movieUrls[$title] ?? null;
            if ($url !== null) {
                $results[$title] = $url;
                unset($remaining[$title]);
            }
            Cache::put('showmeta.tmdb.'.md5($title), $url, self::CACHE_TTL_SECONDS);
        }
    }

    /**
     * @param  array<string, Response|ConnectException>  $responses
     * @param  list<string>  $titles
     * @return array<string, string|null>
     */
    private function parseTmdbTvResponses(array $responses, array $titles): array
    {
        $urls = [];

        foreach ($titles as $title) {
            $key = md5($title);
            $response = $responses[$key] ?? null;

            if ($response instanceof Response && $response->successful()) {
                $results = $response->json('results', []);
                if (! empty($results) && isset($results[0]['poster_path'])) {
                    $urls[$title] = self::TMDB_IMAGE_BASE.$results[0]['poster_path'];
                }
            }
        }

        return $urls;
    }

    /**
     * @param  array<string, Response|ConnectException>  $responses
     * @param  list<string>  $titles
     * @return array<string, string|null>
     */
    private function parseTmdbMovieResponses(array $responses, array $titles): array
    {
        $urls = [];

        foreach ($titles as $title) {
            $key = md5($title);
            $response = $responses[$key] ?? null;

            if ($response instanceof Response && $response->successful()) {
                $results = $response->json('results', []);
                if (! empty($results) && isset($results[0]['poster_path'])) {
                    $urls[$title] = self::TMDB_IMAGE_BASE.$results[0]['poster_path'];
                }
            }
        }

        return $urls;
    }

    /**
     * Separate titles into cache hits and misses.
     *
     * @param  list<string>  $titles
     * @return array{hits: array<string, string|null>, misses: list<string>}
     */
    private function filterCached(array $titles, callable $cacheKey): array
    {
        $hits = [];
        $misses = [];

        foreach ($titles as $title) {
            $key = $cacheKey($title);
            $cached = Cache::get($key);

            if ($cached !== null) {
                $hits[$title] = $cached;
            } else {
                $misses[] = $title;
            }
        }

        return ['hits' => $hits, 'misses' => $misses];
    }

    /**
     * Resolve whether each (title, season, episode) tuple aired within $thresholdDays.
     *
     * For each unique show title, fetches `/singlesearch/shows?embed=episodes` (one request
     * per title) and caches the full episodes list for 5 days. Requests for multiple titles
     * are dispatched concurrently via Http::pool.
     *
     * @param  array<array{title: string, season: int, episode: int}>  $lookups
     * @param  int  $thresholdDays  Episodes that aired within this many days are considered new
     * @return array<string, bool> Keyed by md5("{title}:{season}:{episode}")
     */
    public function resolveEpisodeIsNew(array $lookups, int $thresholdDays = 14): array
    {
        if (empty($lookups)) {
            return [];
        }

        // Deduplicate by composite key
        $unique = [];
        foreach ($lookups as $lookup) {
            $key = md5("{$lookup['title']}:{$lookup['season']}:{$lookup['episode']}");
            $unique[$key] = $lookup;
        }

        $results = array_fill_keys(array_keys($unique), false);
        $cutoff = now()->subDays($thresholdDays);

        // Group lookups by normalised title key so we make one TVMaze call per show
        $titleKeyToItems = [];
        foreach ($unique as $key => $lookup) {
            $titleKey = md5(mb_strtolower(trim($lookup['title'])));
            $titleKeyToItems[$titleKey][] = ['key' => $key, 'lookup' => $lookup];
        }

        // Separate cached vs. uncached show titles
        $titleEpisodes = [];
        $titlesToFetch = [];

        foreach ($titleKeyToItems as $titleKey => $items) {
            $cached = Cache::get('showmeta.tvmaze_eps.'.$titleKey);

            if ($cached !== null) {
                $titleEpisodes[$titleKey] = $cached;
            } else {
                $titlesToFetch[$titleKey] = $items[0]['lookup']['title'];
            }
        }

        // Fetch uncached shows concurrently (one request per title, episodes embedded)
        if (! empty($titlesToFetch)) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $titleKey) => $pool->as($titleKey)
                    ->timeout(10)
                    ->get(self::TVMAZE_BASE_URL.'/singlesearch/shows', [
                        'q' => $titlesToFetch[$titleKey],
                        'embed' => 'episodes',
                    ]),
                array_keys($titlesToFetch),
            ));

            foreach ($titlesToFetch as $titleKey => $title) {
                $response = $responses[$titleKey] ?? null;
                $episodes = [];

                if ($response instanceof Response && $response->successful()) {
                    $show = $response->json();
                    if (! empty($show)) {
                        $episodes = $show['_embedded']['episodes'] ?? [];
                    }
                } elseif ($response !== null && ! ($response instanceof ConnectException)) {
                    Log::debug("ShowMetadata: TVMaze episode fetch failed for \"{$title}\"");
                }

                $titleEpisodes[$titleKey] = $episodes;
                Cache::put('showmeta.tvmaze_eps.'.$titleKey, $episodes, self::TVMAZE_EPS_CACHE_TTL_SECONDS);
            }
        }

        // Match each lookup to its TVMaze episode and compare airdate to cutoff
        foreach ($unique as $key => $lookup) {
            $titleKey = md5(mb_strtolower(trim($lookup['title'])));
            $episodes = $titleEpisodes[$titleKey] ?? [];

            foreach ($episodes as $ep) {
                if ((int) ($ep['season'] ?? -1) === $lookup['season']
                    && (int) ($ep['number'] ?? -1) === $lookup['episode']
                ) {
                    $airdate = $ep['airdate'] ?? null;
                    if (! empty($airdate)) {
                        $results[$key] = Carbon::parse($airdate)->greaterThanOrEqualTo($cutoff);
                    }
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * Resolve posters via TVMaze API (free, no API key required).
     *
     * @param  array<string, string|null>  $results
     * @param  array<string, true>  $remaining
     */
    private function resolveFromTvMaze(array &$results, array &$remaining): void
    {
        $titles = array_keys($remaining);
        $pending = $this->filterCached($titles, fn (string $title) => 'showmeta.tvmaze.'.md5(mb_strtolower(trim($title))));

        foreach ($pending['hits'] as $title => $url) {
            if ($url !== null) {
                $results[$title] = $url;
                unset($remaining[$title]);
            }
        }

        if (empty($pending['misses'])) {
            return;
        }

        $responses = Http::pool(fn (Pool $pool) => array_map(
            fn (string $title) => $pool->as(md5(mb_strtolower(trim($title))))
                ->timeout(10)
                ->get(self::TVMAZE_BASE_URL.'/singlesearch/shows', [
                    'q' => $title,
                ]),
            $pending['misses'],
        ));

        foreach ($pending['misses'] as $title) {
            $key = md5(mb_strtolower(trim($title)));
            $response = $responses[$key] ?? null;

            $url = null;

            if ($response instanceof Response && $response->successful()) {
                $show = $response->json();
                if (! empty($show)) {
                    $url = $show['image']['original'] ?? $show['image']['medium'] ?? null;
                }
            } elseif ($response !== null && ! ($response instanceof ConnectException)) {
                Log::debug("ShowMetadata: TVMaze unexpected status for \"{$title}\"");
            }

            if ($url !== null) {
                $results[$title] = $url;
                unset($remaining[$title]);
            }

            Cache::put('showmeta.tvmaze.'.md5(mb_strtolower(trim($title))), $url, self::CACHE_TTL_SECONDS);
        }
    }
}
