<?php

namespace App\Services;

use App\Models\DvrRecording;
use App\Settings\GeneralSettings;
use App\Support\EpisodeNumberParser;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DvrMetadataEnricherService — Enriches DvrRecording rows with TMDB/TVMaze data.
 *
 * Enrichment happens in three passes for TV content:
 *
 *   Pass 1 — Show-level metadata (TMDB → TVMaze fallback).
 *            Identifies the show, retrieves poster/overview/first_air_date.
 *
 *   Pass 2 — Season/episode backfill.
 *            If recording.season or recording.episode is null but
 *            epg_programme_data.episode_num holds an un-parsed string,
 *            parse it now and write back to the recording row so that
 *            episode-level fetch (Pass 3) and VOD integration have correct numbers.
 *
 *   Pass 3 — Episode-level metadata (TMDB → TVMaze fallback).
 *            Requires show ID + season + episode from Passes 1–2.
 *            Retrieves episode-specific: plot, still image, air date.
 *            Stored under metadata.tmdb_episode / metadata.tvmaze_episode.
 *
 * Sources (in priority order):
 *   1. TMDB — requires an API key (per-DvrSetting or global config)
 *   2. TVMaze — free, no API key required (fallback)
 */
class DvrMetadataEnricherService
{
    private const TMDB_BASE_URL = 'https://api.themoviedb.org/3';

    private const TMDB_IMAGE_BASE = 'https://image.tmdb.org/t/p/w500';

    private const TMDB_STILL_BASE = 'https://image.tmdb.org/t/p/w300';

    private const TVMAZE_BASE_URL = 'https://api.tvmaze.com';

    private const CACHE_TTL_SECONDS = 86400; // 24 hours — positive results

    private const CACHE_MISS_TTL_SECONDS = 3600; // 1 hour — confirmed misses (no match found)

    public function __construct(private readonly GeneralSettings $generalSettings) {}

    /**
     * Enrich a recording with metadata.
     *
     * Pass 1: show-level (TMDB → TVMaze).
     * Pass 2: season/episode backfill from epg_programme_data.episode_num.
     * Pass 3: episode-level (TMDB → TVMaze) when season + episode are known.
     */
    public function enrich(DvrRecording $recording): void
    {
        $tmdbKey = $this->resolveTmdbApiKey($recording);
        $enriched = false;

        // ── Pass 1: show-level metadata ────────────────────────────────────────
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
            Log::info("DVR metadata: no show-level metadata found for recording {$recording->id} — proceeding without enrichment", [
                'recording_id' => $recording->id,
                'title' => $recording->title,
            ]);
        }

        // ── Pass 2: season/episode backfill ────────────────────────────────────
        // Re-read the recording to pick up any metadata saved in Pass 1.
        $recording->refresh();
        $this->backfillSeasonEpisode($recording);

        // ── Pass 3: episode-level metadata ─────────────────────────────────────
        // Only meaningful when we know both season and episode numbers.
        if ($recording->season !== null && $recording->episode !== null) {
            $recording->refresh();
            $this->enrichEpisodeLevel($recording, $tmdbKey);
        }
    }

    // ── Pass 2: season/episode backfill ───────────────────────────────────────

    /**
     * If recording.season or recording.episode is null, attempt to parse them from
     * epg_programme_data.episode_num (stored raw XMLTV string at schedule time).
     *
     * This covers recordings created before EpgCacheService gained multi-format
     * episode_num parsing, where EPG columns were left null even though the raw
     * string contained parseable S/E info.
     */
    private function backfillSeasonEpisode(DvrRecording $recording): void
    {
        if ($recording->season !== null && $recording->episode !== null) {
            return; // nothing to backfill
        }

        $raw = $recording->epg_programme_data['episode_num'] ?? null;

        if (empty($raw)) {
            return;
        }

        [$season, $episode] = EpisodeNumberParser::fromRaw($raw);

        if ($season === null && $episode === null) {
            return;
        }

        $updates = [];

        if ($recording->season === null && $season !== null) {
            $updates['season'] = $season;
        }

        if ($recording->episode === null && $episode !== null) {
            $updates['episode'] = $episode;
        }

        if (! empty($updates)) {
            $recording->update($updates);

            Log::info("DVR metadata: backfilled season/episode for recording {$recording->id} from episode_num '{$raw}'", [
                'recording_id' => $recording->id,
                'season' => $updates['season'] ?? $recording->season,
                'episode' => $updates['episode'] ?? $recording->episode,
            ]);
        }
    }

    // ── Pass 3: episode-level metadata ────────────────────────────────────────

    /**
     * Fetch episode-specific metadata (plot, still image, air date) from TMDB or TVMaze.
     * Requires that the recording already has season + episode numbers.
     */
    private function enrichEpisodeLevel(DvrRecording $recording, ?string $tmdbKey): void
    {
        $metadata = $recording->metadata ?? [];
        $episodeEnriched = false;

        // TMDB episode lookup — needs show TMDB ID from Pass 1
        $tmdbShowId = $metadata['tmdb']['id'] ?? null;

        if ($tmdbKey && $tmdbShowId && ($metadata['tmdb']['type'] ?? null) === 'tv') {
            $recording->update(['post_processing_step' => 'Fetching TMDB episode metadata']);

            try {
                $episodeData = $this->fetchTmdbEpisode(
                    (int) $tmdbShowId,
                    (int) $recording->season,
                    (int) $recording->episode,
                    $tmdbKey
                );

                if ($episodeData) {
                    $metadata['tmdb_episode'] = $episodeData;
                    $recording->update(['metadata' => $metadata]);
                    $episodeEnriched = true;

                    Log::debug("DVR metadata: TMDB episode metadata applied for recording {$recording->id}", [
                        'tmdb_show_id' => $tmdbShowId,
                        'season' => $recording->season,
                        'episode' => $recording->episode,
                    ]);
                }
            } catch (Exception $e) {
                Log::warning("DVR metadata: TMDB episode fetch failed for recording {$recording->id}: {$e->getMessage()}");
            }
        }

        // TVMaze episode lookup — needs TVMaze show ID from Pass 1
        if (! $episodeEnriched) {
            $tvmazeShowId = $metadata['tvmaze']['id'] ?? null;

            if ($tvmazeShowId) {
                $recording->update(['post_processing_step' => 'Fetching TVMaze episode metadata']);

                try {
                    $episodeData = $this->fetchTvMazeEpisode(
                        (int) $tvmazeShowId,
                        (int) $recording->season,
                        (int) $recording->episode
                    );

                    if ($episodeData) {
                        $metadata['tvmaze_episode'] = $episodeData;
                        $recording->update(['metadata' => $metadata]);

                        Log::debug("DVR metadata: TVMaze episode metadata applied for recording {$recording->id}", [
                            'tvmaze_show_id' => $tvmazeShowId,
                            'season' => $recording->season,
                            'episode' => $recording->episode,
                        ]);
                    }
                } catch (Exception $e) {
                    Log::warning("DVR metadata: TVMaze episode fetch failed for recording {$recording->id}: {$e->getMessage()}");
                }
            }
        }
    }

    // ── Pass 1 helpers ────────────────────────────────────────────────────────

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
     * Fetch show-level metadata from TMDB.
     * Searches TV first (most DVR content is TV), falls back to movies.
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
     * Fetch show-level metadata from TVMaze.
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

    // ── Pass 3 helpers ────────────────────────────────────────────────────────

    /**
     * Fetch episode-specific metadata from TMDB.
     * Endpoint: GET /tv/{show_id}/season/{season}/episode/{episode}
     *
     * @return array<string, mixed>|null
     */
    private function fetchTmdbEpisode(int $showId, int $season, int $episode, string $apiKey): ?array
    {
        $cacheKey = "dvr.tmdb.episode.{$showId}.{$season}.{$episode}";

        if (Cache::has($cacheKey)) {
            $data = Cache::get($cacheKey);

            return $data ?: null;
        }

        $url = self::TMDB_BASE_URL."/tv/{$showId}/season/{$season}/episode/{$episode}";
        $response = Http::timeout(10)->get($url, ['api_key' => $apiKey]);

        if (! $response->successful()) {
            Cache::put($cacheKey, false, self::CACHE_MISS_TTL_SECONDS);

            return null;
        }

        $ep = $response->json();

        if (empty($ep)) {
            Cache::put($cacheKey, false, self::CACHE_MISS_TTL_SECONDS);

            return null;
        }

        $data = [
            'id' => $ep['id'] ?? null,
            'name' => $ep['name'] ?? null,
            'overview' => $ep['overview'] ?? null,
            'still_url' => isset($ep['still_path'])
                ? self::TMDB_STILL_BASE.$ep['still_path']
                : null,
            'air_date' => $ep['air_date'] ?? null,
            'episode_number' => $ep['episode_number'] ?? $episode,
            'season_number' => $ep['season_number'] ?? $season,
        ];

        Cache::put($cacheKey, $data, self::CACHE_TTL_SECONDS);

        return $data;
    }

    /**
     * Fetch episode-specific metadata from TVMaze.
     * Endpoint: GET /shows/{show_id}/episodebynumber?season={s}&number={e}
     *
     * @return array<string, mixed>|null
     */
    private function fetchTvMazeEpisode(int $showId, int $season, int $episode): ?array
    {
        $cacheKey = "dvr.tvmaze.episode.{$showId}.{$season}.{$episode}";

        if (Cache::has($cacheKey)) {
            $data = Cache::get($cacheKey);

            return $data ?: null;
        }

        $response = Http::timeout(10)->get(self::TVMAZE_BASE_URL."/shows/{$showId}/episodebynumber", [
            'season' => $season,
            'number' => $episode,
        ]);

        if (! $response->successful()) {
            Cache::put($cacheKey, false, self::CACHE_MISS_TTL_SECONDS);

            return null;
        }

        $ep = $response->json();

        if (empty($ep)) {
            Cache::put($cacheKey, false, self::CACHE_MISS_TTL_SECONDS);

            return null;
        }

        $data = [
            'id' => $ep['id'] ?? null,
            'name' => $ep['name'] ?? null,
            'summary' => $ep['summary'] ? html_entity_decode(strip_tags($ep['summary'])) : null,
            'image' => $ep['image']['original'] ?? $ep['image']['medium'] ?? null,
            'airdate' => $ep['airdate'] ?? null,
        ];

        Cache::put($cacheKey, $data, self::CACHE_TTL_SECONDS);

        return $data;
    }

    // ── Shared helpers ────────────────────────────────────────────────────────

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
