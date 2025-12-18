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
        $this->confidenceThreshold = $settings->tmdb_confidence_threshold ?? 80;
    }

    /**
     * Check if TMDB integration is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Search for a movie by title and optionally year.
     *
     * @param string $title The movie title to search for
     * @param int|null $year The release year (optional, will be extracted from title if not provided)
     * @param bool $tryFallback Whether to try fallback search strategies
     * @return array|null Returns movie data with tmdb_id and imdb_id, or null if not found
     */
    public function searchMovie(string $title, ?int $year = null, bool $tryFallback = true): ?array
    {
        if (!$this->isConfigured()) {
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

            $response = Http::timeout(15)->get(self::BASE_URL . '/search/movie', $params);

            if (!$response->successful()) {
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
            $match = $this->findBestMatch($results, $title, $year, 'title', 'release_date');

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
                if (!empty($results) && $year) {
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

            $response = Http::timeout(15)->get(self::BASE_URL . '/search/movie', $params);

            if (!$response->successful()) {
                return null;
            }

            $results = $response->json('results', []);

            if (empty($results)) {
                // Try without year
                if ($year) {
                    unset($params['year']);
                    $response = Http::timeout(15)->get(self::BASE_URL . '/search/movie', $params);
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
     * @param string $name The series name to search for
     * @param int|null $year The first air date year (optional)
     * @return array|null Returns series data with tmdb_id, tvdb_id, and imdb_id, or null if not found
     */
    public function searchTvSeries(string $name, ?int $year = null): ?array
    {
        if (!$this->isConfigured()) {
            Log::warning('TMDB API key not configured');
            return null;
        }

        $this->waitForRateLimit();

        try {
            $params = [
                'api_key' => $this->apiKey,
                'query' => $this->normalizeTitle($name),
                'language' => $this->language,
                'include_adult' => false,
            ];

            if ($year) {
                $params['first_air_date_year'] = $year;
            }

            $response = Http::timeout(15)->get(self::BASE_URL . '/search/tv', $params);

            if (!$response->successful()) {
                Log::warning('TMDB TV search failed', [
                    'name' => $name,
                    'status' => $response->status(),
                ]);
                return null;
            }

            $results = $response->json('results', []);

            if (empty($results)) {
                // Try without year if we had one
                if ($year) {
                    return $this->searchTvSeries($name, null);
                }
                return null;
            }

            // Find best match
            $match = $this->findBestMatch($results, $name, $year, 'name', 'first_air_date');

            if (!$match) {
                return null;
            }

            // Get external IDs (for TVDB and IMDB)
            $externalIds = $this->getTvExternalIds($match['id']);

            return [
                'tmdb_id' => $match['id'],
                'tvdb_id' => $externalIds['tvdb_id'] ?? null,
                'imdb_id' => $externalIds['imdb_id'] ?? null,
                'name' => $match['name'] ?? null,
                'first_air_date' => $match['first_air_date'] ?? null,
                'confidence' => $match['_confidence'] ?? 0,
            ];
        } catch (\Exception $e) {
            Log::error('TMDB TV search error', [
                'name' => $name,
                'error' => $e->getMessage(),
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
                self::BASE_URL . "/movie/{$tmdbId}/external_ids",
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
                self::BASE_URL . "/tv/{$tmdbId}/external_ids",
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
     * Find the best match from search results based on title and year.
     */
    protected function findBestMatch(array $results, string $searchTitle, ?int $searchYear, string $titleField, string $dateField): ?array
    {
        // Normalize the search title for comparison (same way we normalize for search)
        $normalizedSearch = $this->normalizeForComparison($this->normalizeTitle($searchTitle));
        $bestMatch = null;
        $bestScore = 0;

        foreach ($results as $result) {
            $resultTitle = $result[$titleField] ?? '';
            $resultDate = $result[$dateField] ?? '';
            $resultYear = $resultDate ? (int) substr($resultDate, 0, 4) : null;

            // Calculate title similarity using normalized search title
            $normalizedResult = $this->normalizeForComparison($resultTitle);
            $similarity = $this->calculateSimilarity($normalizedSearch, $normalizedResult);
            
            // Also check original title if available (for non-English results)
            if (isset($result['original_title']) && $result['original_title'] !== $resultTitle) {
                $normalizedOriginal = $this->normalizeForComparison($result['original_title']);
                $originalSimilarity = $this->calculateSimilarity($normalizedSearch, $normalizedOriginal);
                $similarity = max($similarity, $originalSimilarity);
            }

            // Year matching bonus/penalty
            $yearScore = 0;
            if ($searchYear && $resultYear) {
                $yearDiff = abs($searchYear - $resultYear);
                if ($yearDiff === 0) {
                    $yearScore = 15; // Exact year match bonus
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
        
        // Remove audio format info in parentheses: "(Dolby Atmos)", "(DTS-HD)", etc.
        $title = preg_replace('/\s*\((?:Dolby\s*)?(?:Atmos|Vision|DTS(?:-HD)?|TrueHD|Digital|HDR|HDR10\+?|Directors?\s*Cut)\)/i', '', $title);
        
        // Remove brackets with technical info: [4K], [UHD], [DE], etc.
        $title = preg_replace('/\s*\[[^\]]*\]/i', '', $title);
        
        // Remove year in parentheses from title (will be used as separate param)
        $title = preg_replace('/\s*\(\d{4}\)\s*/', '', $title);
        
        // Remove quality suffixes anywhere in the title (with slash, space, or hyphen)
        // Matches: 4K/UHD, 4KUHD, 4K UHD, 4K-UHD, UHD, HD, FHD, 720p, 1080p, 2160p, etc.
        $title = preg_replace('/\s*[-\/\s]*(4K\s*[\/\-]?\s*U?HD|UHD|FHD|HD|SD|720p|1080p|2160p|4K|REMUX|BluRay|Blu-Ray|BDRip|WEBRip|WEB-DL|HDRip|HDTV|DVDRip)/i', '', $title);
        
        // Remove language tags: DE, EN, GER, ENG, German, English, Multi, etc.
        $title = preg_replace('/\s*[-\s]*(DE|EN|GER|ENG|German|English|Deutsch|Multi|Dual|Audio)\s*$/i', '', $title);
        
        // Remove year at end of title (will be extracted separately): "Atlas 2024" -> "Atlas"
        $title = preg_replace('/\s+\d{4}\s*$/', '', $title);
        
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
     * @param string $title Title that may contain year in format "Title (YYYY)" or "Title YYYY" or "Title YYYY 4KUHD"
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
}