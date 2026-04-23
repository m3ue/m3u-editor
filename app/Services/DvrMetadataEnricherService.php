<?php

namespace App\Services;

use App\Models\DvrRecording;
use App\Settings\GeneralSettings;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DvrMetadataEnricherService — Enriches DvrRecording rows with TMDB/TVMaze data.
 *
 * Sources (in priority order):
 *   1. TMDB — requires an API key (per-DvrSetting or global config)
 *   2. TVMaze — free, no API key required (fallback)
 */
class DvrMetadataEnricherService
{
    private const TMDB_BASE_URL = 'https://api.themoviedb.org/3';

    private const TMDB_IMAGE_BASE = 'https://image.tmdb.org/t/p/w500';

    private const TVMAZE_BASE_URL = 'https://api.tvmaze.com';

    private const CACHE_TTL_SECONDS = 86400; // 24 hours — positive results

    private const CACHE_MISS_TTL_SECONDS = 3600; // 1 hour — confirmed misses (no match found)

    public function __construct(private readonly GeneralSettings $generalSettings) {}

    /**
     * Enrich a recording with metadata.
     * Tries TMDB first, falls back to TVMaze.
     */
    public function enrich(DvrRecording $recording): void
    {
        $tmdbKey = $this->resolveTmdbApiKey($recording);
        $enriched = false;

        // Attempt TMDB
        if ($tmdbKey) {
            $recording->update(['post_processing_step' => 'Fetching TMDB metadata']);

            try {
                $enriched = $this->enrichFromTmdb($recording, $tmdbKey);
            } catch (Exception $e) {
                Log::warning("DVR metadata: TMDB enrichment failed for recording {$recording->id}: {$e->getMessage()}", [
                    'recording_id' => $recording->id,
                    'title' => $recording->title,
                ]);
            }
        }

        // Fallback to TVMaze
        if (! $enriched) {
            $recording->update(['post_processing_step' => 'Fetching TVMaze metadata']);

            try {
                $enriched = $this->enrichFromTvMaze($recording);
            } catch (Exception $e) {
                Log::warning("DVR metadata: TVMaze enrichment failed for recording {$recording->id}: {$e->getMessage()}", [
                    'recording_id' => $recording->id,
                    'title' => $recording->title,
                ]);
            }
        }

        if (! $enriched) {
            Log::info("DVR metadata: no metadata found for recording {$recording->id} — proceeding without enrichment", [
                'recording_id' => $recording->id,
                'title' => $recording->title,
            ]);
        }
    }

    /**
     * Normalize a title for use as a search query.
     *
     * Strips Unicode decorations (superscripts, subscripts, combining marks,
     * and other non-Latin symbols) that prevent TMDB/TVMaze from matching the
     * actual show name.  The original title is preserved in the database.
     */
    private function normalizeSearchTitle(string $title): string
    {
        // Strip Unicode characters outside the basic Latin/extended Latin range.
        // This removes superscripts like ᴸᶦᵛᵉ, emoji, and other decoration while
        // preserving accented Latin characters (e.g. é, ñ, ü).
        $normalized = preg_replace('/[^\x{0000}-\x{024F}\s]/u', '', $title) ?? $title;

        // Collapse extra whitespace left behind after stripping
        return trim((string) preg_replace('/\s{2,}/', ' ', $normalized));
    }

    /**
     * Enrich from TMDB. Returns true if a match was found and applied.
     */
    private function enrichFromTmdb(DvrRecording $recording, string $apiKey): bool
    {
        $title = $this->normalizeSearchTitle($recording->title);
        $cacheKey = 'dvr.tmdb.'.md5($title);

        if (Cache::has($cacheKey)) {
            $data = Cache::get($cacheKey);
            // false is a cached "no match found" sentinel — avoid re-querying the API.
            if ($data === false) {
                return false;
            }
        } else {
            $data = $this->fetchTmdb($title, $apiKey);
            if ($data !== null) {
                Cache::put($cacheKey, $data, self::CACHE_TTL_SECONDS);
            } else {
                Cache::put($cacheKey, false, self::CACHE_MISS_TTL_SECONDS);
            }
        }

        if (empty($data)) {
            return false;
        }

        $metadata = $recording->metadata ?? [];
        $metadata['tmdb'] = $data;

        $recording->update(['metadata' => $metadata]);

        Log::debug("DVR metadata: TMDB enrichment applied for recording {$recording->id}", [
            'title' => $title,
            'tmdb_id' => $data['id'] ?? null,
        ]);

        return true;
    }

    /**
     * Fetch metadata from TMDB for a title (TV show or movie).
     *
     * @return array<string, mixed>|null
     */
    private function fetchTmdb(string $title, string $apiKey): ?array
    {
        // Search TV shows first (most DVR content is TV)
        $searchUrl = self::TMDB_BASE_URL.'/search/tv';
        $response = Http::timeout(10)->get($searchUrl, [
            'api_key' => $apiKey,
            'query' => $title,
        ]);

        if ($response->successful()) {
            $results = $response->json('results', []);
            if (! empty($results)) {
                $match = $results[0];

                return [
                    'id' => $match['id'],
                    'type' => 'tv',
                    'name' => $match['name'] ?? $match['title'] ?? $title,
                    'overview' => $match['overview'] ?? null,
                    'poster_url' => isset($match['poster_path'])
                        ? self::TMDB_IMAGE_BASE.$match['poster_path']
                        : null,
                    'backdrop_url' => isset($match['backdrop_path'])
                        ? self::TMDB_IMAGE_BASE.$match['backdrop_path']
                        : null,
                    'first_air_date' => $match['first_air_date'] ?? null,
                ];
            }
        }

        // Try movies as a fallback
        $searchUrl = self::TMDB_BASE_URL.'/search/movie';
        $response = Http::timeout(10)->get($searchUrl, [
            'api_key' => $apiKey,
            'query' => $title,
        ]);

        if ($response->successful()) {
            $results = $response->json('results', []);
            if (! empty($results)) {
                $match = $results[0];

                return [
                    'id' => $match['id'],
                    'type' => 'movie',
                    'name' => $match['title'] ?? $title,
                    'overview' => $match['overview'] ?? null,
                    'poster_url' => isset($match['poster_path'])
                        ? self::TMDB_IMAGE_BASE.$match['poster_path']
                        : null,
                    'backdrop_url' => isset($match['backdrop_path'])
                        ? self::TMDB_IMAGE_BASE.$match['backdrop_path']
                        : null,
                    'release_date' => $match['release_date'] ?? null,
                ];
            }
        }

        return null;
    }

    /**
     * Enrich from TVMaze (free, no API key required).
     * Returns true if a match was found and applied.
     */
    private function enrichFromTvMaze(DvrRecording $recording): bool
    {
        $title = $this->normalizeSearchTitle($recording->title);
        $cacheKey = 'dvr.tvmaze.'.md5($title);

        if (Cache::has($cacheKey)) {
            $data = Cache::get($cacheKey);
            // false is a cached "no match found" sentinel — avoid re-querying the API.
            if ($data === false) {
                return false;
            }
        } else {
            $data = $this->fetchTvMaze($title);
            if ($data !== null) {
                Cache::put($cacheKey, $data, self::CACHE_TTL_SECONDS);
            } else {
                Cache::put($cacheKey, false, self::CACHE_MISS_TTL_SECONDS);
            }
        }

        if (empty($data)) {
            return false;
        }

        $metadata = $recording->metadata ?? [];
        $metadata['tvmaze'] = $data;

        $recording->update(['metadata' => $metadata]);

        Log::debug("DVR metadata: TVMaze enrichment applied for recording {$recording->id}", [
            'title' => $title,
            'tvmaze_id' => $data['id'] ?? null,
        ]);

        return true;
    }

    /**
     * Fetch show metadata from TVMaze.
     *
     * @return array<string, mixed>|null
     */
    private function fetchTvMaze(string $title): ?array
    {
        $response = Http::timeout(10)->get(self::TVMAZE_BASE_URL.'/singlesearch/shows', [
            'q' => $title,
        ]);

        if (! $response->successful()) {
            return null;
        }

        $show = $response->json();

        if (empty($show)) {
            return null;
        }

        return [
            'id' => $show['id'],
            'name' => $show['name'] ?? $title,
            'overview' => $show['summary'] ? html_entity_decode(strip_tags($show['summary'])) : null,
            'poster_url' => $show['image']['original'] ?? $show['image']['medium'] ?? null,
            'premiered' => $show['premiered'] ?? null,
            'genres' => $show['genres'] ?? [],
            'network' => $show['network']['name'] ?? null,
        ];
    }

    /**
     * Resolve the TMDB API key: per-DvrSetting first, then global GeneralSettings, then env fallback.
     */
    private function resolveTmdbApiKey(DvrRecording $recording): ?string
    {
        $setting = $recording->dvrSetting;

        if ($setting && ! empty($setting->tmdb_api_key)) {
            return $setting->tmdb_api_key;
        }

        $globalKey = $this->generalSettings->tmdb_api_key;
        if (! empty($globalKey)) {
            return $globalKey;
        }

        return config('dvr.tmdb_api_key') ?: null;
    }
}
