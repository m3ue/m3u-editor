<?php

namespace App\Services;

use App\Models\DvrRecording;
use App\Settings\GeneralSettings;
use App\Support\EpisodeNumberParser;
use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * DvrMetadataEnricherService — Enriches DvrRecording rows with TMDB/TVMaze data.
 *
 * Enrichment happens in four passes for TV content:
 *
 *   Pass 1 — Show-level metadata (TMDB → TVMaze fallback).
 *            Identifies the show, retrieves poster/overview/first_air_date.
 *
 *   Pass 2 — Season/episode backfill from EPG.
 *            If recording.season or recording.episode is null but
 *            epg_programme_data.episode_num holds an un-parsed string,
 *            parse it now and write back to the recording row.
 *
 *   Pass 2b — Description-prefix backfill.
 *            Some providers omit <episode-num> entirely and embed S/E in
 *            the start of <desc> (e.g. "S01 E06 Landfall\nSynopsis...").
 *            EpisodeNumberParser::fromDescription() pulls S/E + episode
 *            title from the prefix, strips the prefix from the description,
 *            and promotes the episode title to subtitle when subtitle is
 *            empty.
 *
 *   Pass 2.5 — Air-date episode resolution (TMDB only).
 *            Last-resort numeric resolver for TV recordings whose EPG
 *            omits episode-num entirely. Walks TMDB seasons looking for
 *            an episode whose air_date matches the recording's
 *            programme_start (date component, in app timezone). Only
 *            accepts a result if exactly one episode across all seasons
 *            matches — otherwise falls through to MMDD synthesis at
 *            integration time.
 *
 *   Pass 3 — Episode-level metadata (TMDB → TVMaze fallback).
 *            Requires show ID + season + episode from Passes 1–2.5.
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

        // ── Pass 2b: description-prefix backfill ───────────────────────────────
        // Some providers omit <episode-num> entirely and embed S/E in the
        // description prefix (e.g. "S01 E06 Landfall\nSynopsis...").
        $recording->refresh();
        if ($recording->season === null || $recording->episode === null) {
            $this->backfillFromDescription($recording);
        }

        // ── Pass 2.5: air-date episode resolution ──────────────────────────────
        // For TV recordings still missing season/episode, try to resolve them
        // from TMDB by matching programme_start against episode air_dates.
        $recording->refresh();
        if ($tmdbKey && $recording->season === null && $recording->episode === null) {
            $this->resolveSeasonEpisodeFromAirDate($recording, $tmdbKey);
        }

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

    // ── Pass 2b: description-prefix backfill ─────────────────────────────────

    /**
     * Backfill season/episode/subtitle from the start of the EPG description.
     *
     * Common XMLTV pattern: providers omit <episode-num> but write S/E and the
     * episode title at the start of <desc>:
     *
     *   "S01 E06 Landfall\nAfter having a breakthrough, Cameron is at odds..."
     *
     * EpisodeNumberParser::fromDescription() returns [season, episode, title].
     * We:
     *   - Update recording.season / recording.episode (only when null) so
     *     downstream Pass 3 + integration use real numbers.
     *   - Update recording.subtitle to the extracted title when subtitle is
     *     empty (it's the canonical episode title for this airing).
     *   - Strip the matched prefix from recording.description so the same
     *     "S01 E06 Landfall" line doesn't appear twice in the UI.
     */
    private function backfillFromDescription(DvrRecording $recording): void
    {
        $description = (string) $recording->description;
        if (trim($description) === '') {
            return;
        }

        [$season, $episode, $extractedTitle] = EpisodeNumberParser::fromDescription($description);

        // No anchored S/E pattern; nothing to backfill.
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

        // Promote extracted title to subtitle when subtitle is empty.
        if ($extractedTitle !== null && trim((string) $recording->subtitle) === '') {
            $updates['subtitle'] = $extractedTitle;
        }

        // Strip the matched prefix from the description.  The patterns in
        // EpisodeNumberParser are anchored at start; we re-run them here to
        // know how much to strip.  Using the same patterns keeps strip and
        // parse in lock-step.
        $stripped = $this->stripDescriptionPrefix($description);
        if ($stripped !== null && $stripped !== $description) {
            $updates['description'] = $stripped;
        }

        if (! empty($updates)) {
            $recording->update($updates);

            Log::info("DVR metadata: backfilled season/episode for recording {$recording->id} from description prefix", [
                'recording_id' => $recording->id,
                'season' => $updates['season'] ?? $recording->season,
                'episode' => $updates['episode'] ?? $recording->episode,
                'subtitle' => $updates['subtitle'] ?? null,
            ]);
        }
    }

    /**
     * Strip a leading "S01 E06 [Title]" / "1x06 [Title]" / "Episode 6 [Title]"
     * prefix from the description, returning the cleaned remainder. Returns
     * null when no anchored prefix matches.
     */
    private function stripDescriptionPrefix(string $description): ?string
    {
        $patterns = [
            '/^\s*S\d{1,3}\s*E\d{1,3}\b\s*[:.\-\x{2013}\x{2014}]?\s*[^\r\n.\x{2013}\x{2014}]*[.\x{2013}\x{2014}]?\s*\n?/iu',
            '/^\s*\d{1,3}x\d{1,3}\b\s*[:.\-\x{2013}\x{2014}]?\s*[^\r\n.\x{2013}\x{2014}]*[.\x{2013}\x{2014}]?\s*\n?/iu',
            '/^\s*Season\s+\d{1,3}\s*[,:]?\s+Episode\s+\d{1,3}\b\s*[:.\-\x{2013}\x{2014}]?\s*[^\r\n.\x{2013}\x{2014}]*[.\x{2013}\x{2014}]?\s*\n?/iu',
            '/^\s*Ep(?:isode)?\.?\s+\d{1,3}\b\s*[:.\-\x{2013}\x{2014}]?\s*[^\r\n.\x{2013}\x{2014}]*[.\x{2013}\x{2014}]?\s*\n?/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $description, $m)) {
                $remainder = mb_substr($description, mb_strlen($m[0]));
                $remainder = ltrim($remainder);

                return $remainder !== '' ? $remainder : null;
            }
        }

        return null;
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
            // fetchTmdb returns:
            //   array  → confirmed match (cache positive)
            //   false  → confirmed miss (200 OK + empty results, cache negative)
            //   null   → transient failure (non-2xx / exception, do NOT cache)
            $data = $this->fetchTmdb($title, $apiKey);
            if (is_array($data)) {
                Cache::put($cacheKey, $data, self::CACHE_TTL_SECONDS);
            } elseif ($data === false) {
                Cache::put($cacheKey, false, self::CACHE_MISS_TTL_SECONDS);
            }
            // null → leave cache untouched so the next attempt retries the API
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
     * @return array<string, mixed>|false|null
     *                                         - array: confirmed match
     *                                         - false: confirmed miss (both TV and movie searches returned empty)
     *                                         - null:  transient failure (non-2xx response on either search)
     */
    private function fetchTmdb(string $title, string $apiKey): array|false|null
    {
        // Search TV shows first (most DVR content is TV)
        $searchUrl = self::TMDB_BASE_URL.'/search/tv';
        $response = Http::timeout(10)->get($searchUrl, [
            'api_key' => $apiKey,
            'query' => $title,
        ]);

        if (! $response->successful()) {
            return null; // transient failure — do not cache
        }

        $results = $response->json('results', []);
        if (! empty($results)) {
            $match = $this->selectBestTmdbResult($results, $title, 'tv');

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

        // Try movies as a fallback
        $searchUrl = self::TMDB_BASE_URL.'/search/movie';
        $response = Http::timeout(10)->get($searchUrl, [
            'api_key' => $apiKey,
            'query' => $title,
        ]);

        if (! $response->successful()) {
            return null; // transient failure — do not cache
        }

        $results = $response->json('results', []);
        if (! empty($results)) {
            $match = $this->selectBestTmdbResult($results, $title, 'movie');

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

        return false; // confirmed miss — both endpoints returned 200 with empty results
    }

    /**
     * Pick the best result from a TMDB search response.
     *
     * TMDB returns results sorted by its own internal relevance score (mostly
     * popularity).  Blindly taking results[0] picks fuzzy matches over exact
     * name matches when the fuzzy match happens to be more popular — e.g. a
     * recording titled "The Office" can resolve to "The Office (UK)" when the
     * UK version outranks the US version on popularity for the search term.
     *
     * Ranking, in priority order:
     *   1. Exact case-insensitive name match (after trim)
     *   2. Highest popularity (TMDB's own score, default 0)
     *   3. Lowest array index (preserve TMDB's tiebreaker)
     *
     * @param  array<int, array<string, mixed>>  $results
     * @param  'tv'|'movie'  $type
     * @return array<string, mixed>
     */
    private function selectBestTmdbResult(array $results, string $title, string $type): array
    {
        $titleField = $type === 'movie' ? 'title' : 'name';
        $needle = mb_strtolower(trim($title));

        $best = null;
        $bestScore = null;

        foreach ($results as $index => $result) {
            $candidate = mb_strtolower(trim((string) ($result[$titleField] ?? '')));
            $exact = ($candidate !== '' && $candidate === $needle) ? 1 : 0;
            $popularity = (float) ($result['popularity'] ?? 0);

            // Build a composite score; lower index breaks ties.  Negate index
            // so larger == better.
            $score = [$exact, $popularity, -$index];

            if ($bestScore === null || $score > $bestScore) {
                $best = $result;
                $bestScore = $score;
            }
        }

        return $best ?? $results[0];
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
            // fetchTvMaze returns:
            //   array  → confirmed match (cache positive)
            //   false  → confirmed miss (404 from singlesearch, cache negative)
            //   null   → transient failure (5xx / network error, do NOT cache)
            $data = $this->fetchTvMaze($title);
            if (is_array($data)) {
                Cache::put($cacheKey, $data, self::CACHE_TTL_SECONDS);
            } elseif ($data === false) {
                Cache::put($cacheKey, false, self::CACHE_MISS_TTL_SECONDS);
            }
            // null → leave cache untouched so the next attempt retries the API
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
     * TVMaze /singlesearch/shows returns 404 with empty body on confirmed miss,
     * which we treat as a cacheable negative result. 5xx or network errors
     * are transient and must NOT be cached.
     *
     * @return array<string, mixed>|false|null
     *                                         - array: confirmed match
     *                                         - false: confirmed miss (404)
     *                                         - null:  transient failure (other non-2xx)
     */
    private function fetchTvMaze(string $title): array|false|null
    {
        $response = Http::timeout(10)->get(self::TVMAZE_BASE_URL.'/singlesearch/shows', [
            'q' => $title,
        ]);

        if ($response->status() === 404) {
            return false; // confirmed miss
        }

        if (! $response->successful()) {
            return null; // transient failure — do not cache
        }

        $show = $response->json();

        if (empty($show)) {
            return false; // confirmed miss — successful response with empty body
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
     * Returns null on both transient failures (not cached) and confirmed misses
     * (cached). TMDB returns 404 when the episode doesn't exist, which is a
     * cacheable confirmed miss; 5xx is transient and must not be cached.
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

        if ($response->status() === 404) {
            // Confirmed miss — episode doesn't exist on TMDB
            Cache::put($cacheKey, false, self::CACHE_MISS_TTL_SECONDS);

            return null;
        }

        if (! $response->successful()) {
            // Transient failure (5xx, rate-limit, network) — do NOT cache
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
     * Returns null on both transient failures (not cached) and confirmed
     * misses (cached). TVMaze returns 404 when the episode doesn't exist;
     * 5xx is transient and must not be cached.
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

        if ($response->status() === 404) {
            Cache::put($cacheKey, false, self::CACHE_MISS_TTL_SECONDS);

            return null;
        }

        if (! $response->successful()) {
            // Transient failure — do NOT cache
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

    // ── Pass 2.5: air-date episode resolution ─────────────────────────────────

    /**
     * Last-resort numeric resolver for TV recordings whose EPG omits episode-num
     * entirely. Walks TMDB seasons looking for an episode whose air_date matches
     * the recording's programme_start (date component, in app timezone).
     *
     * Only accepts a result if exactly one episode across all seasons matches —
     * zero matches or ambiguous matches fall through to MMDD synthesis at
     * integration time.
     *
     * Caching: show details and individual season lists are cached with the
     * standard positive/negative TTLs, so subsequent recordings of the same
     * show only re-fetch newly-aired seasons.
     */
    private function resolveSeasonEpisodeFromAirDate(DvrRecording $recording, string $tmdbKey): void
    {
        $metadata = $recording->metadata ?? [];
        $tmdbId = $metadata['tmdb']['id'] ?? null;
        $type = $metadata['tmdb']['type'] ?? null;

        if (! $tmdbId || $type !== 'tv') {
            return;
        }

        if (! $recording->programme_start) {
            return;
        }

        $airDate = Carbon::parse($recording->programme_start)->toDateString();

        try {
            $seasonNumbers = $this->fetchTmdbShowSeasonNumbers((int) $tmdbId, $tmdbKey);
        } catch (\Throwable $e) {
            Log::debug("DVR metadata: air-date show fetch failed for recording {$recording->id}: {$e->getMessage()}");

            return;
        }

        if ($seasonNumbers === null) {
            return;
        }

        $matches = [];

        foreach ($seasonNumbers as $seasonNumber) {
            try {
                $episodes = $this->fetchTmdbSeasonEpisodes((int) $tmdbId, $seasonNumber, $tmdbKey);
            } catch (\Throwable $e) {
                Log::debug("DVR metadata: air-date season fetch failed (S{$seasonNumber}) for recording {$recording->id}: {$e->getMessage()}");

                continue;
            }

            if (! is_array($episodes)) {
                continue;
            }

            foreach ($episodes as $ep) {
                if (($ep['air_date'] ?? null) === $airDate) {
                    $matches[] = [
                        'season' => $seasonNumber,
                        'episode' => (int) $ep['episode_number'],
                    ];
                }
            }
        }

        if (count($matches) !== 1) {
            if (count($matches) > 1) {
                Log::debug("DVR metadata: air-date matched multiple episodes for recording {$recording->id} — falling through to MMDD", [
                    'tmdb_id' => $tmdbId,
                    'air_date' => $airDate,
                    'match_count' => count($matches),
                ]);
            }

            return;
        }

        $recording->update($matches[0]);

        Log::info("DVR metadata: resolved season/episode via air-date match for recording {$recording->id}", [
            'recording_id' => $recording->id,
            'tmdb_id' => $tmdbId,
            'air_date' => $airDate,
            'season' => $matches[0]['season'],
            'episode' => $matches[0]['episode'],
        ]);
    }

    /**
     * Fetch season numbers for a TMDB TV show.
     *
     * Returns an array of non-zero season_number values, or null on any
     * non-recoverable failure (transient HTTP error, missing data).
     *
     * @return int[]|null
     */
    private function fetchTmdbShowSeasonNumbers(int $tmdbId, string $apiKey): ?array
    {
        $cacheKey = "dvr.tmdb.show.{$tmdbId}";

        if (Cache::has($cacheKey)) {
            $data = Cache::get($cacheKey);

            return $data ?: null;
        }

        $url = self::TMDB_BASE_URL."/tv/{$tmdbId}";
        $response = Http::timeout(10)->get($url, ['api_key' => $apiKey]);

        if (! $response->successful()) {
            // Transient — do NOT cache
            return null;
        }

        $show = $response->json();

        if (empty($show) || empty($show['seasons'])) {
            // 200 with no useful seasons data — cache as confirmed-miss
            Cache::put($cacheKey, false, self::CACHE_MISS_TTL_SECONDS);

            return null;
        }

        $seasonNumbers = collect($show['seasons'])
            ->pluck('season_number')
            ->filter(fn ($n) => is_numeric($n) && (int) $n > 0)
            ->map(fn ($n) => (int) $n)
            ->values()
            ->toArray();

        Cache::put($cacheKey, $seasonNumbers, self::CACHE_TTL_SECONDS);

        return $seasonNumbers;
    }

    /**
     * Fetch episodes for a single season from TMDB.
     *
     * Returns episodes array on success, null on transient failure, and
     * caches confirmed-miss when the season has no episodes.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function fetchTmdbSeasonEpisodes(int $tmdbId, int $seasonNumber, string $apiKey): ?array
    {
        $cacheKey = "dvr.tmdb.season.{$tmdbId}.{$seasonNumber}";

        if (Cache::has($cacheKey)) {
            $data = Cache::get($cacheKey);

            return $data ?: null;
        }

        $url = self::TMDB_BASE_URL."/tv/{$tmdbId}/season/{$seasonNumber}";
        $response = Http::timeout(10)->get($url, ['api_key' => $apiKey]);

        if ($response->status() === 404) {
            Cache::put($cacheKey, false, self::CACHE_MISS_TTL_SECONDS);

            return null;
        }

        if (! $response->successful()) {
            // Transient — do NOT cache
            return null;
        }

        $data = $response->json();

        if (empty($data) || empty($data['episodes'])) {
            Cache::put($cacheKey, false, self::CACHE_MISS_TTL_SECONDS);

            return null;
        }

        $episodes = $data['episodes'];

        Cache::put($cacheKey, $episodes, self::CACHE_TTL_SECONDS);

        return $episodes;
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
