<?php

namespace App\Services;

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Service for interacting with The Movie Database (TMDB) API.
 * Provides methods to search for movies and TV series and retrieve their metadata IDs.
 */
class TmdbService
{
    protected const BASE_URL = 'https://api.themoviedb.org/3';

    protected ?string $apiKey;

    protected string $language;

    protected int $rateLimit;

    protected int $confidenceThreshold;

    /**
     * Create a new TmdbService instance.
     */
    public function __construct(?GeneralSettings $settings = null)
    {
        $settings = $settings ?? app(GeneralSettings::class);
        $this->apiKey = $settings->tmdb_api_key;
        $this->language = $settings->tmdb_language ?? 'en-US';
        $this->rateLimit = $settings->tmdb_rate_limit ?? 40;
        $this->confidenceThreshold = $settings->tmdb_confidence_threshold ?? 70; // Lowered from 80 to 70
    }

    /**
     * Check if TMDB integration is configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Search for a movie by title and optionally year.
     *
     * @param  string  $title  The movie title to search for
     * @param  int|null  $year  The release year (optional, will be extracted from title if not provided)
     * @param  bool  $tryFallback  Whether to try fallback search strategies
     * @return array|null Returns movie data with tmdb_id and imdb_id, or null if not found
     */
    public function searchMovie(string $title, ?int $year = null, bool $tryFallback = true): ?array
    {
        if (! $this->isConfigured()) {
            Log::warning('TMDB API key not configured');

            return null;
        }

        // Extract year from title if not provided
        if ($year === null) {
            $year = self::extractYearFromTitle($title);
        }

        $this->waitForRateLimit();

        try {
            $normalizedTitle = $this->normalizeTitle($title);

            Log::debug('TMDB searching movie', [
                'original' => $title,
                'normalized' => $normalizedTitle,
                'year' => $year,
                'language' => $this->language,
            ]);

            $params = [
                'api_key' => $this->apiKey,
                'query' => $normalizedTitle,
                'language' => $this->language,
                'include_adult' => false,
            ];

            if ($year) {
                $params['year'] = $year;
            }

            $response = Http::timeout(15)->get(self::BASE_URL.'/search/movie', $params);

            if (! $response->successful()) {
                Log::warning('TMDB movie search failed', [
                    'title' => $title,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $results = $response->json('results', []);

            if (empty($results)) {
                // Try without year if we had one
                if ($year) {
                    Log::debug('TMDB: No results with year, retrying without year', ['title' => $normalizedTitle]);

                    return $this->searchMovie($title, null, $tryFallback);
                }

                // Try removing subtitle after " - " (common in German titles)
                if ($tryFallback && str_contains($normalizedTitle, ' - ')) {
                    $mainTitle = trim(explode(' - ', $normalizedTitle)[0]);
                    Log::debug('TMDB: No results, trying without subtitle', ['main_title' => $mainTitle]);

                    return $this->searchMovieSimple($mainTitle, TmdbService::extractYearFromTitle($title));
                }

                // Try fallback: search with different language
                if ($tryFallback && $this->language !== 'en-US') {
                    Log::debug('TMDB: No results with language, trying with en-US fallback', ['title' => $normalizedTitle]);
                    $originalLanguage = $this->language;
                    $this->language = 'en-US';
                    $result = $this->searchMovie($title, TmdbService::extractYearFromTitle($title), false);
                    $this->language = $originalLanguage;

                    return $result;
                }

                Log::debug('TMDB: No results found', ['title' => $normalizedTitle]);

                return null;
            }

            Log::debug('TMDB search results', [
                'title' => $normalizedTitle,
                'count' => count($results),
                'first_result' => $results[0]['title'] ?? 'N/A',
            ]);

            // Find best match
            $match = $this->findBestMatch($results, $title, $year, 'title', 'release_date', 'movie');

            if ($match) {
                // Get external IDs (for IMDB)
                $externalIds = $this->getMovieExternalIds($match['id']);

                return [
                    'tmdb_id' => $match['id'],
                    'imdb_id' => $externalIds['imdb_id'] ?? null,
                    'title' => $match['title'] ?? null,
                    'release_date' => $match['release_date'] ?? null,
                    'confidence' => $match['_confidence'] ?? 0,
                ];
            }

            // No good match - try fallback strategies
            if ($tryFallback) {
                // Strategy 1: Remove subtitle after " - " and search again
                if (str_contains($normalizedTitle, ' - ')) {
                    $mainTitle = trim(explode(' - ', $normalizedTitle)[0]);
                    Log::debug('TMDB: Trying without subtitle', ['main_title' => $mainTitle]);
                    $fallbackResult = $this->searchMovieSimple($mainTitle, $year);
                    if ($fallbackResult) {
                        return $fallbackResult;
                    }
                }

                // Strategy 2: Remove suffix after " : "
                if (str_contains($normalizedTitle, ': ')) {
                    $mainTitle = trim(explode(': ', $normalizedTitle)[0]);
                    Log::debug('TMDB: Trying without colon suffix', ['main_title' => $mainTitle]);
                    $fallbackResult = $this->searchMovieSimple($mainTitle, $year);
                    if ($fallbackResult) {
                        return $fallbackResult;
                    }
                }

                // Strategy 3: Take first result if year matches exactly
                if (! empty($results) && $year) {
                    foreach ($results as $result) {
                        $resultYear = isset($result['release_date']) ? (int) substr($result['release_date'], 0, 4) : null;
                        if ($resultYear === $year) {
                            Log::debug('TMDB: Using first result with matching year', [
                                'title' => $result['title'] ?? 'N/A',
                                'year' => $resultYear,
                            ]);
                            $externalIds = $this->getMovieExternalIds($result['id']);

                            return [
                                'tmdb_id' => $result['id'],
                                'imdb_id' => $externalIds['imdb_id'] ?? null,
                                'title' => $result['title'] ?? null,
                                'release_date' => $result['release_date'] ?? null,
                                'confidence' => 50,
                            ];
                        }
                    }
                }

                // Strategy 4: Try different language
                $fallbackLang = $this->language === 'en-US' ? 'de-DE' : 'en-US';
                Log::debug('TMDB: Trying with fallback language', [
                    'title' => $normalizedTitle,
                    'fallback_language' => $fallbackLang,
                ]);
                $originalLanguage = $this->language;
                $this->language = $fallbackLang;
                $result = $this->searchMovie($title, $year, false);
                $this->language = $originalLanguage;

                return $result;
            }

            return null;
        } catch (\Exception $e) {
            Log::error('TMDB movie search error', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Simple movie search that takes the first result if year matches.
     * Used as fallback when main search fails due to localized titles.
     */
    protected function searchMovieSimple(string $title, ?int $year = null): ?array
    {
        $this->waitForRateLimit();

        try {
            $params = [
                'api_key' => $this->apiKey,
                'query' => $title,
                'language' => $this->language,
                'include_adult' => false,
            ];

            if ($year) {
                $params['year'] = $year;
            }

            $response = Http::timeout(15)->get(self::BASE_URL.'/search/movie', $params);

            if (! $response->successful()) {
                return null;
            }

            $results = $response->json('results', []);

            if (empty($results)) {
                // Try without year
                if ($year) {
                    unset($params['year']);
                    $response = Http::timeout(15)->get(self::BASE_URL.'/search/movie', $params);
                    $results = $response->json('results', []);
                }
            }

            if (empty($results)) {
                return null;
            }

            // Take first result - if we have a year, prefer matching year
            $match = $results[0];
            if ($year) {
                foreach ($results as $result) {
                    $resultYear = isset($result['release_date']) ? (int) substr($result['release_date'], 0, 4) : null;
                    if ($resultYear === $year) {
                        $match = $result;
                        break;
                    }
                }
            }

            Log::debug('TMDB simple search found match', [
                'query' => $title,
                'match' => $match['title'] ?? 'N/A',
                'tmdb_id' => $match['id'],
            ]);

            // Get external IDs
            $externalIds = $this->getMovieExternalIds($match['id']);

            return [
                'tmdb_id' => $match['id'],
                'imdb_id' => $externalIds['imdb_id'] ?? null,
                'title' => $match['title'] ?? null,
                'release_date' => $match['release_date'] ?? null,
                'confidence' => 60, // Lower confidence for simple search
            ];
        } catch (\Exception $e) {
            Log::error('TMDB simple search error', [
                'title' => $title,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Search for a TV series by name and optionally year.
     *
     * @param  string  $name  The series name to search for
     * @param  int|null  $year  The first air date year (optional)
     * @return array|null Returns series data with tmdb_id, tvdb_id, and imdb_id, or null if not found
     */
    public function searchTvSeries(string $name, ?int $year = null): ?array
    {
        if (! $this->isConfigured()) {
            Log::warning('TMDB API key not configured');

            return null;
        }

        $this->waitForRateLimit();

        try {
            $normalizedQuery = $this->normalizeTitle($name);

            // Build search language priority list:
            // 1. User's configured language
            // 2. English as fallback (if not already the configured language)
            $searchLanguages = [$this->language];
            if ($this->language !== 'en-US') {
                $searchLanguages[] = 'en-US';
            }

            // Try each language
            foreach ($searchLanguages as $lang) {
                $params = [
                    'api_key' => $this->apiKey,
                    'query' => $normalizedQuery,
                    'language' => $lang,
                    'include_adult' => false,
                ];

                if ($year) {
                    $params['first_air_date_year'] = $year;
                }

                Log::debug('TMDB: Searching for TV series', [
                    'original_name' => $name,
                    'normalized_query' => $normalizedQuery,
                    'year' => $year,
                    'language' => $lang,
                ]);

                $response = Http::timeout(15)->get(self::BASE_URL.'/search/tv', $params);

                if (! $response->successful()) {
                    Log::warning('TMDB TV search failed', [
                        'name' => $name,
                        'normalized_query' => $normalizedQuery,
                        'year' => $year,
                        'language' => $lang,
                        'status' => $response->status(),
                        'response' => $response->body(),
                    ]);

                    continue;
                }

                $results = $response->json('results', []);

                Log::debug('TMDB: TV search results', [
                    'name' => $name,
                    'normalized_query' => $normalizedQuery,
                    'year' => $year,
                    'language' => $lang,
                    'result_count' => count($results),
                    'first_results' => array_slice($results, 0, 3),
                ]);

                if (! empty($results)) {
                    // Try to find best match with this language
                    $match = $this->findBestMatch($results, $name, $year, 'name', 'first_air_date', 'tv');

                    if ($match) {
                        // Found a good match, use it!
                        Log::debug('TMDB: Best match found', [
                            'search_name' => $name,
                            'matched_name' => $match['name'] ?? null,
                            'tmdb_id' => $match['id'],
                            'first_air_date' => $match['first_air_date'] ?? null,
                            'confidence' => $match['_confidence'] ?? 0,
                            'language_used' => $lang,
                        ]);

                        // Get external IDs (for TVDB and IMDB)
                        $externalIds = $this->getTvExternalIds($match['id']);

                        Log::debug('TMDB: External IDs retrieved', [
                            'tmdb_id' => $match['id'],
                            'tvdb_id' => $externalIds['tvdb_id'] ?? null,
                            'imdb_id' => $externalIds['imdb_id'] ?? null,
                        ]);

                        return [
                            'tmdb_id' => $match['id'],
                            'tvdb_id' => $externalIds['tvdb_id'] ?? null,
                            'imdb_id' => $externalIds['imdb_id'] ?? null,
                            'name' => $match['name'] ?? null,
                            'first_air_date' => $match['first_air_date'] ?? null,
                            'confidence' => $match['_confidence'] ?? 0,
                        ];
                    }
                }
            }

            // No good match found with any language, try without year as fallback
            if ($year) {
                Log::debug('TMDB: No results with year, retrying without year', [
                    'name' => $name,
                    'year' => $year,
                ]);

                return $this->searchTvSeries($name, null);
            }

            Log::info('TMDB: No TV series found', [
                'name' => $name,
                'normalized_query' => $normalizedQuery,
            ]);

            return null;
        } catch (\Exception $e) {
            Log::error('TMDB TV search error', [
                'name' => $name,
                'year' => $year,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Get external IDs for a movie (IMDB, etc.).
     */
    public function getMovieExternalIds(int $tmdbId): array
    {
        $this->waitForRateLimit();

        try {
            $response = Http::timeout(15)->get(
                self::BASE_URL."/movie/{$tmdbId}/external_ids",
                ['api_key' => $this->apiKey]
            );

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Exception $e) {
            Log::error('TMDB get movie external IDs error', [
                'tmdb_id' => $tmdbId,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Get external IDs for a TV series (TVDB, IMDB, etc.).
     */
    public function getTvExternalIds(int $tmdbId): array
    {
        $this->waitForRateLimit();

        try {
            $response = Http::timeout(15)->get(
                self::BASE_URL."/tv/{$tmdbId}/external_ids",
                ['api_key' => $this->apiKey]
            );

            if ($response->successful()) {
                return $response->json();
            }
        } catch (\Exception $e) {
            Log::error('TMDB get TV external IDs error', [
                'tmdb_id' => $tmdbId,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }

    /**
     * Get full TV series details from TMDB.
     * Returns poster, overview, genres, rating, etc.
     *
     * @param  int  $tmdbId  The TMDB ID of the TV series
     * @return array|null Full series details or null on error
     */
    public function getTvSeriesDetails(int $tmdbId): ?array
    {
        $this->waitForRateLimit();

        try {
            $response = Http::timeout(15)->get(
                self::BASE_URL."/tv/{$tmdbId}",
                [
                    'api_key' => $this->apiKey,
                    'language' => $this->language,
                    'append_to_response' => 'external_ids,credits,videos',
                ]
            );

            if (! $response->successful()) {
                Log::warning('TMDB: Failed to get TV series details', [
                    'tmdb_id' => $tmdbId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();

            // Extract external IDs
            $imdbId = $data['external_ids']['imdb_id'] ?? null;
            $tvdbId = $data['external_ids']['tvdb_id'] ?? null;

            // Build poster URL
            $posterUrl = null;
            if (! empty($data['poster_path'])) {
                $posterUrl = 'https://image.tmdb.org/t/p/w500'.$data['poster_path'];
            }

            // Build backdrop URL
            $backdropUrl = null;
            if (! empty($data['backdrop_path'])) {
                $backdropUrl = 'https://image.tmdb.org/t/p/original'.$data['backdrop_path'];
            }

            // Extract genres as comma-separated string
            $genres = collect($data['genres'] ?? [])->pluck('name')->implode(', ');

            // Extract cast (top 10 actors)
            $cast = null;
            if (! empty($data['credits']['cast'])) {
                $cast = collect($data['credits']['cast'])
                    ->take(10)
                    ->pluck('name')
                    ->implode(', ');
            }

            // Extract director(s) from crew
            $director = null;
            if (! empty($data['credits']['crew'])) {
                $directors = collect($data['credits']['crew'])
                    ->filter(fn ($crew) => $crew['job'] === 'Director')
                    ->pluck('name')
                    ->implode(', ');

                if (! empty($directors)) {
                    $director = $directors;
                }
            }

            // Extract YouTube trailer
            $youtubeTrailer = null;
            if (! empty($data['videos']['results'])) {
                $trailer = collect($data['videos']['results'])
                    ->firstWhere(function ($video) {
                        return $video['site'] === 'YouTube'
                            && in_array($video['type'], ['Trailer', 'Teaser']);
                    });

                if ($trailer) {
                    $youtubeTrailer = 'https://www.youtube.com/watch?v='.$trailer['key'];
                }
            }

            return [
                'tmdb_id' => $data['id'] ?? null,
                'tvdb_id' => $tvdbId,
                'imdb_id' => $imdbId,
                'name' => $data['name'] ?? null,
                'original_name' => $data['original_name'] ?? null,
                'overview' => $data['overview'] ?? null,
                'poster_url' => $posterUrl,
                'backdrop_url' => $backdropUrl,
                'first_air_date' => $data['first_air_date'] ?? null,
                'genres' => $genres,
                'vote_average' => $data['vote_average'] ?? null,
                'vote_count' => $data['vote_count'] ?? null,
                'status' => $data['status'] ?? null,
                'number_of_seasons' => $data['number_of_seasons'] ?? null,
                'number_of_episodes' => $data['number_of_episodes'] ?? null,
                'cast' => $cast,
                'director' => $director,
                'youtube_trailer' => $youtubeTrailer,
            ];
        } catch (\Exception $e) {
            Log::error('TMDB get TV series details error', [
                'tmdb_id' => $tmdbId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get full movie details from TMDB.
     * Returns poster, overview, genres, rating, etc.
     *
     * @param  int  $tmdbId  The TMDB ID of the movie
     * @return array|null Full movie details or null on error
     */
    public function getMovieDetails(int $tmdbId): ?array
    {
        $this->waitForRateLimit();

        try {
            $response = Http::timeout(15)->get(
                self::BASE_URL."/movie/{$tmdbId}",
                [
                    'api_key' => $this->apiKey,
                    'language' => $this->language,
                    'append_to_response' => 'external_ids,credits,videos',
                ]
            );

            if (! $response->successful()) {
                Log::warning('TMDB: Failed to get movie details', [
                    'tmdb_id' => $tmdbId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();

            // Build poster URL
            $posterUrl = null;
            if (! empty($data['poster_path'])) {
                $posterUrl = 'https://image.tmdb.org/t/p/w500'.$data['poster_path'];
            }

            // Build backdrop URL
            $backdropUrl = null;
            if (! empty($data['backdrop_path'])) {
                $backdropUrl = 'https://image.tmdb.org/t/p/original'.$data['backdrop_path'];
            }

            // Extract genres as comma-separated string
            $genres = collect($data['genres'] ?? [])->pluck('name')->implode(', ');

            // Extract cast (limit to first 10)
            $cast = [];
            if (! empty($data['credits']['cast'])) {
                $cast = collect($data['credits']['cast'])
                    ->take(10)
                    ->pluck('name')
                    ->toArray();
            }

            // Extract director(s)
            $directors = [];
            if (! empty($data['credits']['crew'])) {
                $directors = collect($data['credits']['crew'])
                    ->filter(fn ($person) => $person['job'] === 'Director')
                    ->pluck('name')
                    ->toArray();
            }

            // Extract YouTube trailer
            $youtubeTrailer = null;
            if (! empty($data['videos']['results'])) {
                $trailer = collect($data['videos']['results'])
                    ->where('site', 'YouTube')
                    ->where('type', 'Trailer')
                    ->first();

                if ($trailer) {
                    $youtubeTrailer = 'https://www.youtube.com/watch?v='.$trailer['key'];
                }
            }

            return [
                'tmdb_id' => $data['id'] ?? null,
                'imdb_id' => $data['imdb_id'] ?? $data['external_ids']['imdb_id'] ?? null,
                'title' => $data['title'] ?? null,
                'original_title' => $data['original_title'] ?? null,
                'overview' => $data['overview'] ?? null,
                'poster_url' => $posterUrl,
                'backdrop_url' => $backdropUrl,
                'release_date' => $data['release_date'] ?? null,
                'genres' => $genres,
                'vote_average' => $data['vote_average'] ?? null,
                'vote_count' => $data['vote_count'] ?? null,
                'runtime' => $data['runtime'] ?? null,
                'status' => $data['status'] ?? null,
                'cast' => $cast,
                'director' => $directors,
                'youtube_trailer' => $youtubeTrailer,
            ];
        } catch (\Exception $e) {
            Log::error('TMDB get movie details error', [
                'tmdb_id' => $tmdbId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get alternative titles for a TV series.
     * Returns titles in different languages/regions.
     *
     * @param  int  $tmdbId  The TMDB ID of the TV series
     * @return array Array of alternative titles with country codes
     */
    public function getTvAlternativeTitles(int $tmdbId): array
    {
        $cacheKey = "tmdb_tv_alt_titles_{$tmdbId}";

        // Cache alternative titles for 24 hours
        return Cache::remember($cacheKey, 86400, function () use ($tmdbId) {
            $this->waitForRateLimit();

            try {
                $response = Http::timeout(15)->get(
                    self::BASE_URL."/tv/{$tmdbId}/alternative_titles",
                    ['api_key' => $this->apiKey]
                );

                if ($response->successful()) {
                    $data = $response->json();

                    return $data['results'] ?? [];
                }
            } catch (\Exception $e) {
                Log::error('TMDB get TV alternative titles error', [
                    'tmdb_id' => $tmdbId,
                    'error' => $e->getMessage(),
                ]);
            }

            return [];
        });
    }

    /**
     * Get alternative titles for a movie.
     * Returns titles in different languages/regions.
     *
     * @param  int  $tmdbId  The TMDB ID of the movie
     * @return array Array of alternative titles with country codes
     */
    public function getMovieAlternativeTitles(int $tmdbId): array
    {
        $cacheKey = "tmdb_movie_alt_titles_{$tmdbId}";

        // Cache alternative titles for 24 hours
        return Cache::remember($cacheKey, 86400, function () use ($tmdbId) {
            $this->waitForRateLimit();

            try {
                $response = Http::timeout(15)->get(
                    self::BASE_URL."/movie/{$tmdbId}/alternative_titles",
                    ['api_key' => $this->apiKey]
                );

                if ($response->successful()) {
                    $data = $response->json();

                    return $data['titles'] ?? [];
                }
            } catch (\Exception $e) {
                Log::error('TMDB get movie alternative titles error', [
                    'tmdb_id' => $tmdbId,
                    'error' => $e->getMessage(),
                ]);
            }

            return [];
        });
    }

    /**
     * Check if a search term matches any of the alternative titles.
     *
     * @param  string  $searchTerm  The original search term
     * @param  array  $alternativeTitles  Array of alternative titles from TMDB
     * @return array|null Returns ['matched' => true, 'title' => '...', 'country' => '...'] or null
     */
    protected function matchAlternativeTitle(string $searchTerm, array $alternativeTitles): ?array
    {
        $normalizedSearch = $this->normalizeForComparison($searchTerm);

        foreach ($alternativeTitles as $alt) {
            $altTitle = $alt['title'] ?? $alt['name'] ?? null;
            if (! $altTitle) {
                continue;
            }

            $normalizedAlt = $this->normalizeForComparison($altTitle);

            // Exact match
            if ($normalizedSearch === $normalizedAlt) {
                return [
                    'matched' => true,
                    'title' => $altTitle,
                    'country' => $alt['iso_3166_1'] ?? 'unknown',
                    'confidence' => 100,
                ];
            }

            // High similarity match (>= 90%)
            $similarity = $this->calculateSimilarity($normalizedSearch, $normalizedAlt);
            if ($similarity >= 90) {
                return [
                    'matched' => true,
                    'title' => $altTitle,
                    'country' => $alt['iso_3166_1'] ?? 'unknown',
                    'confidence' => (int) $similarity,
                ];
            }
        }

        return null;
    }

    /**
     * Find the best match from search results based on title and year.
     *
     * @param  array  $results  Search results from TMDB
     * @param  string  $searchTitle  The original search title
     * @param  int|null  $searchYear  The year to match
     * @param  string  $titleField  The field name for the title ('name' for TV, 'title' for movies)
     * @param  string  $dateField  The field name for the date ('first_air_date' for TV, 'release_date' for movies)
     * @param  string  $type  The type of entity: 'tv' or 'movie'
     * @return array|null The best matching result or null
     */
    protected function findBestMatch(array $results, string $searchTitle, ?int $searchYear, string $titleField, string $dateField, string $type = 'tv'): ?array
    {
        // Normalize the search title for comparison (same way we normalize for search)
        $normalizedSearch = $this->normalizeForComparison($this->normalizeTitle($searchTitle));
        $bestMatch = null;
        $bestScore = 0;
        $exactYearMatch = null;

        foreach ($results as $result) {
            $resultTitle = $result[$titleField] ?? '';
            $resultDate = $result[$dateField] ?? '';
            $resultYear = $resultDate ? (int) substr($resultDate, 0, 4) : null;

            // Calculate title similarity using normalized search title
            $normalizedResult = $this->normalizeForComparison($resultTitle);
            $similarity = $this->calculateSimilarity($normalizedSearch, $normalizedResult);

            // Check if search matches original title/name exactly (for localized content)
            $originalField = isset($result['original_name']) ? 'original_name' : 'original_title';
            if (isset($result[$originalField]) && $result[$originalField] !== $resultTitle) {
                $normalizedOriginal = $this->normalizeForComparison($result[$originalField]);
                $originalSimilarity = $this->calculateSimilarity($normalizedSearch, $normalizedOriginal);

                // Exact match on original title/name should be 100% confidence
                if ($normalizedSearch === $normalizedOriginal) {
                    $similarity = 100;
                } else {
                    $similarity = max($similarity, $originalSimilarity);
                }
            }

            // Year matching bonus/penalty
            $yearScore = 0;
            $isExactYearMatch = false;
            if ($searchYear && $resultYear) {
                $yearDiff = abs($searchYear - $resultYear);
                if ($yearDiff === 0) {
                    $yearScore = 15; // Exact year match bonus
                    $isExactYearMatch = true;
                } elseif ($yearDiff === 1) {
                    $yearScore = 10; // ±1 year tolerance bonus
                } elseif ($yearDiff <= 2) {
                    $yearScore = 5; // ±2 year tolerance small bonus
                } else {
                    $yearScore = -10; // Year mismatch penalty
                }
            }

            // Popularity bonus (TMDB returns more popular results first)
            $popularityBonus = isset($result['popularity']) ? min(10, $result['popularity'] / 10) : 0;

            $totalScore = $similarity + $yearScore + $popularityBonus;

            if ($totalScore > $bestScore) {
                $bestScore = $totalScore;
                $bestMatch = $result;
                $bestMatch['_confidence'] = (int) min(100, $similarity);
            }

            // Track if we have an exact year match (useful for localized titles)
            if ($isExactYearMatch && $exactYearMatch === null) {
                $exactYearMatch = $result;
                $exactYearMatch['_confidence'] = (int) min(100, $similarity);
            }
        }

        // Only return if confidence meets threshold
        if ($bestMatch && ($bestMatch['_confidence'] ?? 0) >= $this->confidenceThreshold) {
            Log::debug('TMDB match found', [
                'search' => $searchTitle,
                'match' => $bestMatch[$titleField] ?? 'unknown',
                'confidence' => $bestMatch['_confidence'],
            ]);

            return $bestMatch;
        }

        // FALLBACK 1: Check alternative titles for low-confidence matches
        // This is the most reliable way to match localized titles like "Unsere kleine Farm" → "Little House on the Prairie"
        if ($bestMatch && ($bestMatch['_confidence'] ?? 0) < $this->confidenceThreshold) {
            $tmdbId = $bestMatch['id'] ?? null;
            if ($tmdbId) {
                Log::debug('TMDB: Checking alternative titles for low-confidence match', [
                    'search' => $searchTitle,
                    'tmdb_id' => $tmdbId,
                    'current_confidence' => $bestMatch['_confidence'] ?? 0,
                    'type' => $type,
                ]);

                $alternativeTitles = $type === 'tv'
                    ? $this->getTvAlternativeTitles($tmdbId)
                    : $this->getMovieAlternativeTitles($tmdbId);

                if (! empty($alternativeTitles)) {
                    $altMatch = $this->matchAlternativeTitle($searchTitle, $alternativeTitles);
                    if ($altMatch && $altMatch['matched']) {
                        Log::debug('TMDB match found via alternative titles', [
                            'search' => $searchTitle,
                            'match' => $bestMatch[$titleField] ?? 'unknown',
                            'alt_title_matched' => $altMatch['title'],
                            'alt_country' => $altMatch['country'],
                            'confidence' => $altMatch['confidence'],
                        ]);
                        $bestMatch['_confidence'] = $altMatch['confidence'];
                        $bestMatch['_matched_via'] = 'alternative_title';
                        $bestMatch['_alt_title'] = $altMatch['title'];
                        $bestMatch['_alt_country'] = $altMatch['country'];

                        return $bestMatch;
                    }
                }
            }
        }

        // FALLBACK 2: If only 1 result with exact year match, accept it
        // This handles cases where alternative titles don't exist but TMDB clearly found the right show
        if (count($results) === 1 && $exactYearMatch !== null) {
            Log::debug('TMDB match accepted (single result with exact year match - likely localized title)', [
                'search' => $searchTitle,
                'match' => $exactYearMatch[$titleField] ?? 'unknown',
                'confidence' => $exactYearMatch['_confidence'] ?? 0,
                'year' => $searchYear,
            ]);
            $exactYearMatch['_confidence'] = max($exactYearMatch['_confidence'] ?? 0, 70); // Boost confidence

            return $exactYearMatch;
        }

        // FALLBACK 3: If only 1 result and no year provided, accept with lower confidence
        // This handles cases where we search a localized title and TMDB finds the correct show
        if (count($results) === 1 && $searchYear === null) {
            $singleResult = $results[0];
            $singleResult['_confidence'] = max($bestMatch['_confidence'] ?? 0, 60);
            Log::debug('TMDB match accepted (single result, no year filter - likely localized title)', [
                'search' => $searchTitle,
                'match' => $singleResult[$titleField] ?? 'unknown',
                'confidence' => $singleResult['_confidence'],
            ]);

            return $singleResult;
        }

        // If no match meets threshold but we have results, log for debugging
        if ($bestMatch) {
            Log::debug('TMDB match below confidence threshold', [
                'search' => $searchTitle,
                'normalized_search' => $normalizedSearch,
                'best_match' => $bestMatch[$titleField] ?? 'unknown',
                'confidence' => $bestMatch['_confidence'] ?? 0,
                'threshold' => $this->confidenceThreshold,
            ]);
        }

        return null;
    }

    /**
     * Calculate similarity between two strings (0-100).
     */
    protected function calculateSimilarity(string $str1, string $str2): float
    {
        // Exact match
        if ($str1 === $str2) {
            return 100;
        }

        // Use similar_text for percentage
        similar_text($str1, $str2, $percent);

        // Also check Levenshtein for short strings
        $maxLen = max(strlen($str1), strlen($str2));
        if ($maxLen > 0 && $maxLen < 255) {
            $levenshtein = levenshtein($str1, $str2);
            $levenshteinPercent = (1 - ($levenshtein / $maxLen)) * 100;

            // Use the higher of the two scores
            $percent = max($percent, $levenshteinPercent);
        }

        return $percent;
    }

    /**
     * Normalize a title for search queries.
     */
    protected function normalizeTitle(string $title): string
    {
        // Remove special bullet/marker characters: ●, •, ★, etc. and everything after them
        $title = preg_replace('/\s*[●•★☆■□▪▫►▶◄◀→←↑↓✓✔✗✘].*$/u', '', $title);

        // Remove audio/language info in parentheses: "(Multi)", "(Dolby Atmos)", "(DTS-HD)", etc.
        $title = preg_replace('/\s*\((?:Multi|Dual(?:\s+Audio)?|Dolby(?:\s*Atmos)?|Vision|DTS(?:-HD)?|TrueHD|Digital|HDR|HDR10\+?|Directors?\s*Cut)\)/i', '', $title);

        // Remove brackets with technical info: [4K], [UHD], [DE], etc.
        $title = preg_replace('/\s*\[[^\]]*\]/i', '', $title);

        // Remove year in parentheses from title (will be used as separate param)
        $title = preg_replace('/\s*\(\d{4}\)\s*/', '', $title);

        // Remove quality suffixes anywhere in the title (with slash, space, or hyphen)
        // Matches: 4K/UHD, 4KUHD, 4K UHD, 4K-UHD, UHD, HD, FHD, 720p, 1080p, 2160p, etc.
        $title = preg_replace('/\s*[-\/\s]*(4K\s*[\/\-]?\s*U?HD|UHD|FHD|HD|SD|720p|1080p|2160p|4K|REMUX|BluRay|Blu-Ray|BDRip|WEBRip|WEB-DL|HDRip|HDTV|DVDRip)/i', '', $title);

        // Remove German subtitle after " - " (e.g., "See - Reich der Blinden" -> "See")
        // Only if the subtitle starts with a German article or common German word
        $title = preg_replace('/\s+-\s+(Die|Der|Das|Ein|Eine|Reich|Zeit|Land|Haus)\s+\S+.*$/iu', '', $title);

        // Remove language tags: DE, EN, GER, ENG, German, English, Multi, etc.
        $title = preg_replace('/\s*[-\s]*(DE|EN|GER|ENG|German|English|Deutsch|Multi|Dual|Audio)\s*$/i', '', $title);

        // Remove year at end of title (will be extracted separately): "Atlas 2024" -> "Atlas"
        $title = preg_replace('/\s+\d{4}\s*$/', '', $title);

        // Remove or replace problematic apostrophes and special quotes
        // TMDB search often fails with apostrophes, so we remove them
        $title = str_replace("'", '', $title);  // Regular apostrophe
        $title = str_replace('`', '', $title);  // Backtick
        $title = preg_replace('/\x{2018}|\x{2019}/u', '', $title); // Unicode apostrophes

        // Remove leading/trailing special characters and whitespace
        $title = preg_replace('/^[\s\-:●•]+/', '', $title);
        $title = preg_replace('/[\s\-:●•]+$/', '', $title);

        // Normalize multiple spaces to single space
        $title = preg_replace('/\s+/', ' ', $title);

        return trim($title);
    }

    /**
     * Normalize a string for comparison (more aggressive normalization).
     */
    protected function normalizeForComparison(string $str): string
    {
        // Convert to lowercase
        $str = Str::lower($str);

        // Remove year in parentheses
        $str = preg_replace('/\s*\(\d{4}\)\s*/', ' ', $str);

        // Remove special characters and punctuation
        $str = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $str);

        // Normalize whitespace
        $str = preg_replace('/\s+/', ' ', $str);

        return trim($str);
    }

    /**
     * Wait for rate limit if needed.
     */
    protected function waitForRateLimit(): void
    {
        $key = 'tmdb-api-rate-limit';

        // Use Laravel's rate limiter to enforce a per-second limit.
        if (RateLimiter::tooManyAttempts($key, $this->rateLimit)) {
            $secondsUntilAvailable = RateLimiter::availableIn($key);

            if ($secondsUntilAvailable > 0) {
                usleep($secondsUntilAvailable * 1000000);
            }
        }

        RateLimiter::hit($key, 1);
    }

    /**
     * Extract year from a title string.
     *
     * @param  string  $title  Title that may contain year in format "Title (YYYY)" or "Title YYYY" or "Title YYYY 4KUHD"
     * @return int|null The extracted year or null
     */
    public static function extractYearFromTitle(string $title): ?int
    {
        // Match year in parentheses first: "Title (2023)"
        if (preg_match('/\((\d{4})\)/', $title, $matches)) {
            $year = (int) $matches[1];
            if ($year >= 1900 && $year <= (int) date('Y') + 2) {
                return $year;
            }
        }

        // Match year before quality suffix: "Title 2023 4KUHD"
        if (preg_match('/\s(\d{4})\s*(?:4K|UHD|HD|FHD|720p|1080p|2160p)/i', $title, $matches)) {
            $year = (int) $matches[1];
            if ($year >= 1900 && $year <= (int) date('Y') + 2) {
                return $year;
            }
        }

        // Match year at end: "Title 2023"
        if (preg_match('/\s(\d{4})\s*$/', $title, $matches)) {
            $year = (int) $matches[1];
            if ($year >= 1900 && $year <= (int) date('Y') + 2) {
                return $year;
            }
        }

        return null;
    }

    /**
     * Search for TV series and return multiple results for manual selection.
     *
     * @param  string  $query  The search query (title)
     * @param  int|null  $year  The release year (optional)
     * @param  string|null  $language  Override the default language (optional)
     * @return array Returns array of search results with poster, name, year, overview
     */
    public function searchTvSeriesManual(string $query, ?int $year = null, ?string $language = null): array
    {
        if (! $this->isConfigured()) {
            Log::warning('TMDB API key not configured');

            return [];
        }

        $this->waitForRateLimit();

        try {
            $params = [
                'api_key' => $this->apiKey,
                'query' => $query,
                'language' => $language ?? $this->language,
                'include_adult' => false,
            ];

            if ($year) {
                $params['first_air_date_year'] = $year;
            }

            Log::debug('TMDB: Manual TV search', [
                'query' => $query,
                'year' => $year,
                'language' => $params['language'],
            ]);

            $response = Http::timeout(15)->get(self::BASE_URL.'/search/tv', $params);

            if (! $response->successful()) {
                Log::warning('TMDB manual TV search failed', [
                    'query' => $query,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $results = $response->json('results', []);

            // Transform results for UI display
            return collect($results)->take(10)->map(function ($result) {
                return [
                    'id' => $result['id'],
                    'name' => $result['name'] ?? $result['original_name'] ?? 'Unknown',
                    'original_name' => $result['original_name'] ?? null,
                    'first_air_date' => $result['first_air_date'] ?? null,
                    'year' => isset($result['first_air_date']) ? substr($result['first_air_date'], 0, 4) : null,
                    'overview' => Str::limit($result['overview'] ?? '', 200),
                    'poster_path' => $result['poster_path'] ?? null,
                    'vote_average' => $result['vote_average'] ?? null,
                    'origin_country' => implode(', ', $result['origin_country'] ?? []),
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::error('TMDB manual TV search exception', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Search for movies and return multiple results for manual selection.
     *
     * @param  string  $query  The search query (title)
     * @param  int|null  $year  The release year (optional)
     * @param  string|null  $language  Override the default language (optional)
     * @return array Returns array of search results with poster, title, year, overview
     */
    public function searchMovieManual(string $query, ?int $year = null, ?string $language = null): array
    {
        if (! $this->isConfigured()) {
            Log::warning('TMDB API key not configured');

            return [];
        }

        $this->waitForRateLimit();

        try {
            $params = [
                'api_key' => $this->apiKey,
                'query' => $query,
                'language' => $language ?? $this->language,
                'include_adult' => false,
            ];

            if ($year) {
                $params['primary_release_year'] = $year;
            }

            Log::debug('TMDB: Manual movie search', [
                'query' => $query,
                'year' => $year,
                'language' => $params['language'],
            ]);

            $response = Http::timeout(15)->get(self::BASE_URL.'/search/movie', $params);

            if (! $response->successful()) {
                Log::warning('TMDB manual movie search failed', [
                    'query' => $query,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $results = $response->json('results', []);

            // Transform results for UI display
            return collect($results)->take(10)->map(function ($result) {
                return [
                    'id' => $result['id'],
                    'title' => $result['title'] ?? $result['original_title'] ?? 'Unknown',
                    'name' => $result['title'] ?? $result['original_title'] ?? 'Unknown',
                    'original_title' => $result['original_title'] ?? null,
                    'original_name' => $result['original_title'] ?? null,
                    'release_date' => $result['release_date'] ?? null,
                    'year' => isset($result['release_date']) ? substr($result['release_date'], 0, 4) : null,
                    'overview' => Str::limit($result['overview'] ?? '', 200),
                    'poster_path' => $result['poster_path'] ?? null,
                    'vote_average' => $result['vote_average'] ?? null,
                ];
            })->toArray();
        } catch (\Exception $e) {
            Log::error('TMDB manual movie search exception', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Apply a manually selected TMDB result to a series.
     *
     * @param  int  $tmdbId  The TMDB ID to apply
     * @return array|null Returns the full metadata with external IDs, or null on error
     */
    public function applyTvSeriesSelection(int $tmdbId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $this->waitForRateLimit();

        try {
            // Get external IDs (TVDB, IMDB)
            $externalIds = $this->getTvExternalIds($tmdbId);

            // Get series details for the name
            $params = [
                'api_key' => $this->apiKey,
                'language' => $this->language,
            ];

            $response = Http::timeout(15)->get(self::BASE_URL."/tv/{$tmdbId}", $params);

            if (! $response->successful()) {
                Log::warning('TMDB: Failed to get TV series details', [
                    'tmdb_id' => $tmdbId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $details = $response->json();

            return [
                'tmdb_id' => $tmdbId,
                'tvdb_id' => $externalIds['tvdb_id'] ?? null,
                'imdb_id' => $externalIds['imdb_id'] ?? null,
                'name' => $details['name'] ?? null,
                'original_name' => $details['original_name'] ?? null,
                'first_air_date' => $details['first_air_date'] ?? null,
                'confidence' => 100, // Manual selection = 100% confidence
            ];
        } catch (\Exception $e) {
            Log::error('TMDB: Error applying TV series selection', [
                'tmdb_id' => $tmdbId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Apply a manually selected TMDB result to a movie/VOD.
     *
     * @param  int  $tmdbId  The TMDB ID to apply
     * @return array|null Returns the full metadata with external IDs, or null on error
     */
    public function applyMovieSelection(int $tmdbId): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $this->waitForRateLimit();

        try {
            // Get external IDs (IMDB)
            $externalIds = $this->getMovieExternalIds($tmdbId);

            // Get movie details for the title
            $params = [
                'api_key' => $this->apiKey,
                'language' => $this->language,
            ];

            $response = Http::timeout(15)->get(self::BASE_URL."/movie/{$tmdbId}", $params);

            if (! $response->successful()) {
                Log::warning('TMDB: Failed to get movie details', [
                    'tmdb_id' => $tmdbId,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $details = $response->json();

            return [
                'tmdb_id' => $tmdbId,
                'imdb_id' => $externalIds['imdb_id'] ?? null,
                'title' => $details['title'] ?? null,
                'original_title' => $details['original_title'] ?? null,
                'release_date' => $details['release_date'] ?? null,
                'confidence' => 100, // Manual selection = 100% confidence
            ];
        } catch (\Exception $e) {
            Log::error('TMDB: Error applying movie selection', [
                'tmdb_id' => $tmdbId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get detailed information about a TV series season including episodes.
     *
     * @param  int  $tmdbId  The TMDB ID of the TV series
     * @param  int  $seasonNumber  The season number
     * @return array|null Season details with episodes or null on error
     */
    public function getSeasonDetails(int $tmdbId, int $seasonNumber): ?array
    {
        $this->waitForRateLimit();

        try {
            $response = Http::timeout(15)->get(
                self::BASE_URL."/tv/{$tmdbId}/season/{$seasonNumber}",
                [
                    'api_key' => $this->apiKey,
                    'language' => $this->language,
                    'append_to_response' => 'credits,videos',
                ]
            );

            if (! $response->successful()) {
                Log::warning('TMDB: Failed to get season details', [
                    'tmdb_id' => $tmdbId,
                    'season' => $seasonNumber,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();

            return [
                'season_number' => $data['season_number'] ?? $seasonNumber,
                'name' => $data['name'] ?? null,
                'overview' => $data['overview'] ?? null,
                'air_date' => $data['air_date'] ?? null,
                'poster_path' => $data['poster_path'] ?? null,
                'poster_url' => isset($data['poster_path']) ? "https://image.tmdb.org/t/p/w500{$data['poster_path']}" : null,
                'episodes' => collect($data['episodes'] ?? [])->map(function ($episode) {
                    return [
                        'id' => $episode['id'] ?? null,
                        'tmdb_id' => $episode['id'] ?? null,
                        'episode_number' => $episode['episode_number'] ?? null,
                        'name' => $episode['name'] ?? null,
                        'overview' => $episode['overview'] ?? null,
                        'air_date' => $episode['air_date'] ?? null,
                        'still_path' => $episode['still_path'] ?? null,
                        'vote_average' => $episode['vote_average'] ?? null,
                        'vote_count' => $episode['vote_count'] ?? null,
                        'runtime' => $episode['runtime'] ?? null,
                    ];
                })->toArray(),
            ];
        } catch (\Exception $e) {
            Log::error('TMDB get season details error', [
                'tmdb_id' => $tmdbId,
                'season' => $seasonNumber,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get all seasons for a TV series.
     *
     * @param  int  $tmdbId  The TMDB ID of the TV series
     * @return array Array of season data
     */
    public function getAllSeasons(int $tmdbId): array
    {
        $this->waitForRateLimit();

        try {
            $response = Http::timeout(15)->get(
                self::BASE_URL."/tv/{$tmdbId}",
                [
                    'api_key' => $this->apiKey,
                    'language' => $this->language,
                ]
            );

            if (! $response->successful()) {
                Log::warning('TMDB: Failed to get TV series for seasons', [
                    'tmdb_id' => $tmdbId,
                    'status' => $response->status(),
                ]);

                return [];
            }

            $data = $response->json();

            return $data['seasons'] ?? [];
        } catch (\Exception $e) {
            Log::error('TMDB get all seasons error', [
                'tmdb_id' => $tmdbId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
