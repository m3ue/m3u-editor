<?php

namespace App\Services;

use App\Http\Controllers\LogoProxyController;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\Episode;
use App\Models\Series;
use App\Models\StrmFileMapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class NfoService
{
    /**
     * Generate a tvshow.nfo file for a series (Kodi/Emby/Jellyfin format)
     *
     * @param  Series  $series  The series to generate NFO for
     * @param  string  $path  Directory path where tvshow.nfo should be written
     * @param  StrmFileMapping|null  $mapping  Optional mapping to check/update hash
     * @param  bool  $nameFilterEnabled  Whether name filtering is enabled
     * @param  array  $nameFilterPatterns  Patterns to filter from names
     * @return bool Success status
     */
    public function generateSeriesNfo(Series $series, string $path, $mapping = null, bool $nameFilterEnabled = false, array $nameFilterPatterns = []): bool
    {
        try {
            $metadata = $series->metadata ?? [];

            // Get IDs (ensure scalar values)
            $tmdbId = $this->getScalarValue($metadata['tmdb_id'] ?? $metadata['tmdb'] ?? null);
            $tvdbId = $this->getScalarValue($metadata['tvdb_id'] ?? $metadata['tvdb'] ?? null);
            $imdbId = $this->getScalarValue($metadata['imdb_id'] ?? $metadata['imdb'] ?? null);

            // Build the NFO XML
            $xml = $this->startXml('tvshow');

            // Basic info - apply name filter if enabled
            $seriesName = $this->applyNameFilter($series->name, $nameFilterEnabled, $nameFilterPatterns);
            $xml .= $this->xmlElement('title', $seriesName);
            $xml .= $this->xmlElement('originaltitle', $seriesName);
            $xml .= $this->xmlElement('sorttitle', $seriesName);

            // Plot/Overview
            if (! empty($metadata['plot']) && is_string($metadata['plot'])) {
                $xml .= $this->xmlElement('plot', $metadata['plot']);
                $xml .= $this->xmlElement('outline', $metadata['plot']);
            }

            // Year and dates
            if (! empty($series->release_date) && is_string($series->release_date)) {
                $year = substr($series->release_date, 0, 4);
                $xml .= $this->xmlElement('year', $year);
                $xml .= $this->xmlElement('premiered', $series->release_date);
            }

            // Rating
            if (! empty($metadata['vote_average']) && is_scalar($metadata['vote_average'])) {
                $xml .= $this->xmlElement('rating', $metadata['vote_average']);
            }
            if (! empty($metadata['vote_count']) && is_scalar($metadata['vote_count'])) {
                $xml .= $this->xmlElement('votes', $metadata['vote_count']);
            }

            // Status
            if (! empty($metadata['status']) && is_string($metadata['status'])) {
                $xml .= $this->xmlElement('status', $metadata['status']);
            }

            // Genres
            if (! empty($metadata['genres']) && is_array($metadata['genres'])) {
                foreach ($metadata['genres'] as $genre) {
                    $genreName = is_array($genre) ? ($genre['name'] ?? '') : $genre;
                    if (! empty($genreName)) {
                        $xml .= $this->xmlElement('genre', $genreName);
                    }
                }
            }

            // Studio/Network
            if (! empty($metadata['networks']) && is_array($metadata['networks'])) {
                foreach ($metadata['networks'] as $network) {
                    $networkName = is_array($network) ? ($network['name'] ?? '') : $network;
                    if (! empty($networkName)) {
                        $xml .= $this->xmlElement('studio', $networkName);
                    }
                }
            }

            // Poster
            $poster = $this->getScalarValue($metadata['poster_path'] ?? null);
            if (! empty($poster) && is_string($poster)) {
                // Handle both full URLs and TMDB paths
                $posterUrl = str_starts_with($poster, 'http') ? $poster : 'https://image.tmdb.org/t/p/original'.$poster;
                $xml .= $this->xmlElement('thumb', $posterUrl, ['aspect' => 'poster']);
            }

            // Backdrop
            $backdrop = $this->getScalarValue($metadata['backdrop_path'] ?? null);
            if (! empty($backdrop) && is_string($backdrop)) {
                // Handle both full URLs and TMDB paths
                $backdropUrl = str_starts_with($backdrop, 'http') ? $backdrop : 'https://image.tmdb.org/t/p/original'.$backdrop;
                $xml .= $this->xmlElement('fanart', $backdropUrl);
            }

            // Unique IDs (important for scrapers)
            if (! empty($tmdbId)) {
                $xml .= $this->xmlElement('uniqueid', $tmdbId, ['type' => 'tmdb', 'default' => 'true']);
                $xml .= $this->xmlElement('tmdbid', $tmdbId);
            }
            if (! empty($tvdbId)) {
                $xml .= $this->xmlElement('uniqueid', $tvdbId, ['type' => 'tvdb']);
                $xml .= $this->xmlElement('tvdbid', $tvdbId);
            }
            if (! empty($imdbId)) {
                $xml .= $this->xmlElement('uniqueid', $imdbId, ['type' => 'imdb']);
                $xml .= $this->xmlElement('imdbid', $imdbId);
            }

            $xml .= $this->endXml('tvshow');

            // Ensure directory exists
            if (! is_dir($path)) {
                if (! @mkdir($path, 0755, true)) {
                    Log::error("NfoService: Failed to create directory: {$path}");

                    return false;
                }
            }

            $filePath = rtrim($path, '/').'/tvshow.nfo';

            return $this->writeFileWithHash($filePath, $xml, $mapping);
        } catch (\Throwable $e) {
            Log::error("NfoService: Error generating series NFO for {$series->name}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Generate an episode.nfo file for a series episode
     *
     * @param  Episode  $episode  The episode to generate NFO for
     * @param  Series  $series  The parent series
     * @param  string  $filePath  Path to the .strm file (will be converted to .nfo)
     * @param  StrmFileMapping|null  $mapping  Optional mapping to check/update hash
     * @param  bool  $nameFilterEnabled  Whether name filtering is enabled
     * @param  array  $nameFilterPatterns  Patterns to filter from names
     * @return bool Success status
     */
    public function generateEpisodeNfo(Episode $episode, Series $series, string $filePath, $mapping = null, bool $nameFilterEnabled = false, array $nameFilterPatterns = []): bool
    {
        try {
            $info = $episode->info ?? [];
            $metadata = $series->metadata ?? [];

            // Get IDs (ensure scalar values)
            $tmdbId = $this->getScalarValue($info['tmdb_id'] ?? $info['tmdb'] ?? $metadata['tmdb_id'] ?? $metadata['tmdb'] ?? null);
            $tvdbId = $this->getScalarValue($metadata['tvdb_id'] ?? $metadata['tvdb'] ?? null);
            $imdbId = $this->getScalarValue($metadata['imdb_id'] ?? $metadata['imdb'] ?? null);

            // Build the NFO XML
            $xml = $this->startXml('episodedetails');

            // Basic info - apply name filter if enabled
            $episodeTitle = $this->applyNameFilter($episode->title, $nameFilterEnabled, $nameFilterPatterns);
            $seriesName = $this->applyNameFilter($series->name, $nameFilterEnabled, $nameFilterPatterns);
            $xml .= $this->xmlElement('title', $episodeTitle);
            $xml .= $this->xmlElement('showtitle', $seriesName);

            // Season and Episode
            $xml .= $this->xmlElement('season', $episode->season);
            $xml .= $this->xmlElement('episode', $episode->episode_num);

            // Plot
            if (! empty($info['plot'])) {
                $xml .= $this->xmlElement('plot', $info['plot']);
            }

            // Air date
            if (! empty($info['air_date'])) {
                $xml .= $this->xmlElement('aired', $info['air_date']);
            } elseif (! empty($info['releasedate'])) {
                $xml .= $this->xmlElement('aired', $info['releasedate']);
            }

            // Rating
            if (! empty($info['vote_average'])) {
                $xml .= $this->xmlElement('rating', $info['vote_average']);
            } elseif (! empty($info['rating'])) {
                $xml .= $this->xmlElement('rating', $info['rating']);
            }

            // Runtime (in minutes)
            if (! empty($info['runtime'])) {
                $xml .= $this->xmlElement('runtime', $info['runtime']);
            } elseif (! empty($info['duration_secs'])) {
                $xml .= $this->xmlElement('runtime', round($info['duration_secs'] / 60));
            } elseif (! empty($episode->duration_secs)) {
                $xml .= $this->xmlElement('runtime', round($episode->duration_secs / 60));
            }

            // Thumbnail/Still
            $stillPath = $this->getScalarValue($info['still_path'] ?? null);
            $movieImage = $this->getScalarValue($info['movie_image'] ?? null);
            if (! empty($stillPath) && is_string($stillPath)) {
                // Handle both full URLs and TMDB paths
                $thumbUrl = str_starts_with($stillPath, 'http') ? $stillPath : 'https://image.tmdb.org/t/p/original'.$stillPath;
                $xml .= $this->xmlElement('thumb', $thumbUrl);
            } elseif (! empty($movieImage) && is_string($movieImage)) {
                $xml .= $this->xmlElement('thumb', $movieImage);
            }

            // Unique IDs
            $tmdbEpisodeId = $this->getScalarValue($info['tmdb_episode_id'] ?? null);
            if (! empty($tmdbEpisodeId)) {
                $xml .= $this->xmlElement('uniqueid', $tmdbEpisodeId, ['type' => 'tmdb', 'default' => 'true']);
            } elseif (! empty($tmdbId)) {
                $xml .= $this->xmlElement('uniqueid', $tmdbId, ['type' => 'tmdb', 'default' => 'true']);
            }
            if (! empty($tvdbId)) {
                $xml .= $this->xmlElement('uniqueid', $tvdbId, ['type' => 'tvdb']);
            }
            if (! empty($imdbId)) {
                $xml .= $this->xmlElement('uniqueid', $imdbId, ['type' => 'imdb']);
            }

            $xml .= $this->endXml('episodedetails');

            // Change extension from .strm to .nfo
            $nfoPath = preg_replace('/\.strm$/i', '.nfo', $filePath);

            return $this->writeFileWithHash($nfoPath, $xml, $mapping);
        } catch (\Throwable $e) {
            Log::error("NfoService: Error generating episode NFO for {$episode->title}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Generate a movie.nfo file for a VOD (Kodi/Emby/Jellyfin format)
     *
     * @param  Channel  $channel  The channel/movie to generate NFO for
     * @param  string  $filePath  Path to the .strm file (will be converted to .nfo)
     * @param  StrmFileMapping|null  $mapping  Optional mapping to check/update hash
     * @param  array  $options  Optional options including name_filter_enabled and name_filter_patterns
     * @return bool Success status
     */
    public function generateMovieNfo(Channel $channel, string $filePath, $mapping = null, array $options = []): bool
    {
        try {
            $info = $channel->info ?? [];
            $movieData = $channel->movie_data ?? [];

            // Get name filter settings from options
            $nameFilterEnabled = $options['name_filter_enabled'] ?? false;
            $nameFilterPatterns = $options['name_filter_patterns'] ?? [];

            // Get IDs from multiple sources (ensure scalar values)
            $tmdbId = $this->getScalarValue($info['tmdb_id'] ?? $info['tmdb'] ?? $movieData['tmdb_id'] ?? $movieData['tmdb'] ?? null);
            $imdbId = $this->getScalarValue($info['imdb_id'] ?? $info['imdb'] ?? $movieData['imdb_id'] ?? $movieData['imdb'] ?? null);

            // Build the NFO XML
            $xml = $this->startXml('movie');

            // Basic info - apply name filter if enabled
            $title = $channel->title_custom ?? $channel->title;
            $title = $this->applyNameFilter($title, $nameFilterEnabled, $nameFilterPatterns);
            $xml .= $this->xmlElement('title', $title);
            $xml .= $this->xmlElement('originaltitle', $title);
            $xml .= $this->xmlElement('sorttitle', $title);

            // Plot/Overview
            $plot = $info['plot'] ?? $movieData['plot'] ?? $movieData['description'] ?? null;
            if (! empty($plot)) {
                $xml .= $this->xmlElement('plot', $plot);
                $xml .= $this->xmlElement('outline', mb_substr($plot, 0, 300));
            }

            // Year
            $year = $channel->year ?? $info['year'] ?? $movieData['releasedate'] ?? null;
            if (! empty($year)) {
                // Extract year if it's a full date
                if (strlen($year) > 4) {
                    $year = substr($year, 0, 4);
                }
                $xml .= $this->xmlElement('year', $year);
            }

            // Rating
            $rating = $info['vote_average'] ?? $info['rating'] ?? $movieData['rating'] ?? $movieData['rating_5based'] ?? null;
            if (! empty($rating)) {
                // Convert 5-based rating to 10-based if needed
                if (is_numeric($rating) && $rating <= 5 && isset($movieData['rating_5based'])) {
                    $rating = $rating * 2;
                }
                $xml .= $this->xmlElement('rating', $rating);
            }

            // Runtime (in minutes)
            $runtime = $info['runtime'] ?? $movieData['duration_secs'] ?? null;
            if (! empty($runtime)) {
                // Convert seconds to minutes if > 300 (assume it's in seconds)
                if ($runtime > 300) {
                    $runtime = round($runtime / 60);
                }
                $xml .= $this->xmlElement('runtime', $runtime);
            }

            // Genres
            $genres = $info['genres'] ?? $movieData['genre'] ?? null;
            if (! empty($genres)) {
                if (is_string($genres)) {
                    // Split by comma if it's a string
                    $genreList = array_map('trim', explode(',', $genres));
                } else {
                    $genreList = $genres;
                }
                foreach ($genreList as $genre) {
                    $genreName = is_array($genre) ? ($genre['name'] ?? '') : $genre;
                    if (! empty($genreName)) {
                        $xml .= $this->xmlElement('genre', $genreName);
                    }
                }
            }

            // Director
            $director = $info['director'] ?? $movieData['director'] ?? null;
            if (! empty($director)) {
                $xml .= $this->xmlElement('director', $director);
            }

            // Cast
            $cast = $info['cast'] ?? $movieData['cast'] ?? null;
            if (! empty($cast)) {
                if (is_string($cast)) {
                    $castList = array_map('trim', explode(',', $cast));
                } else {
                    $castList = $cast;
                }
                foreach ($castList as $actor) {
                    $actorName = is_array($actor) ? ($actor['name'] ?? '') : $actor;
                    if (! empty($actorName)) {
                        $xml .= "    <actor>\n";
                        $xml .= $this->xmlElement('name', $actorName, [], 2);
                        if (is_array($actor) && ! empty($actor['character'])) {
                            $xml .= $this->xmlElement('role', $actor['character'], [], 2);
                        }
                        if (is_array($actor) && ! empty($actor['profile_path'])) {
                            $xml .= $this->xmlElement('thumb', 'https://image.tmdb.org/t/p/w185'.$actor['profile_path'], [], 2);
                        }
                        $xml .= "    </actor>\n";
                    }
                }
            }

            // Poster
            $poster = $this->getScalarValue($info['poster_path'] ?? $movieData['cover_big'] ?? $movieData['movie_image'] ?? null);
            if (! empty($poster) && is_string($poster)) {
                $posterUrl = str_starts_with($poster, 'http')
                    ? $poster
                    : 'https://image.tmdb.org/t/p/original'.$poster;
                $xml .= $this->xmlElement('thumb', $posterUrl, ['aspect' => 'poster']);
            }

            // Backdrop
            $backdrop = $this->getScalarValue($info['backdrop_path'] ?? $movieData['backdrop_path'] ?? null);
            if (! empty($backdrop) && is_string($backdrop)) {
                $backdropUrl = str_starts_with($backdrop, 'http')
                    ? $backdrop
                    : 'https://image.tmdb.org/t/p/original'.$backdrop;
                $xml .= $this->xmlElement('fanart', $backdropUrl);
            }

            // Country
            $country = $info['production_countries'] ?? $movieData['country'] ?? null;
            if (! empty($country)) {
                if (is_array($country)) {
                    foreach ($country as $c) {
                        $countryName = is_array($c) ? ($c['name'] ?? $c['iso_3166_1'] ?? '') : $c;
                        if (! empty($countryName)) {
                            $xml .= $this->xmlElement('country', $countryName);
                        }
                    }
                } else {
                    $xml .= $this->xmlElement('country', $country);
                }
            }

            // Unique IDs (important for scrapers)
            if (! empty($tmdbId)) {
                $xml .= $this->xmlElement('uniqueid', $tmdbId, ['type' => 'tmdb', 'default' => 'true']);
                $xml .= $this->xmlElement('tmdbid', $tmdbId);
            }
            if (! empty($imdbId)) {
                $xml .= $this->xmlElement('uniqueid', $imdbId, ['type' => 'imdb']);
                $xml .= $this->xmlElement('imdbid', $imdbId);
            }

            $xml .= $this->endXml('movie');

            // Change extension from .strm to .nfo
            $nfoPath = preg_replace('/\.strm$/i', '.nfo', $filePath);

            return $this->writeFileWithHash($nfoPath, $xml, $mapping);
        } catch (\Throwable $e) {
            Log::error("NfoService: Error generating movie NFO for {$channel->title}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Delete NFO file for an episode
     */
    public function deleteEpisodeNfo(string $strmFilePath): bool
    {
        $nfoPath = preg_replace('/\.strm$/i', '.nfo', $strmFilePath);

        if (file_exists($nfoPath)) {
            return @unlink($nfoPath);
        }

        return true;
    }

    /**
     * Delete NFO file for a movie
     */
    public function deleteMovieNfo(string $strmFilePath): bool
    {
        return $this->deleteEpisodeNfo($strmFilePath);
    }

    /**
     * Delete tvshow.nfo file for a series
     */
    public function deleteSeriesNfo(string $seriesPath): bool
    {
        $nfoPath = rtrim($seriesPath, '/').'/tvshow.nfo';

        if (file_exists($nfoPath)) {
            return @unlink($nfoPath);
        }

        return true;
    }

    /**
     * Start XML document
     * Note: standalone="yes" is used for maximum compatibility with media servers
     */
    private function startXml(string $rootElement): string
    {
        return "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"yes\"?>\n<{$rootElement}>\n";
    }

    /**
     * End XML document
     */
    private function endXml(string $rootElement): string
    {
        return "</{$rootElement}>\n";
    }

    /**
     * Create an XML element with optional attributes
     *
     * Note: Arrays are intentionally skipped and return empty string.
     * Callers should iterate over array values and call this method for each item.
     * See generateSeriesNfo() and generateMovieNfo() for examples of handling arrays (genres, cast, etc.)
     */
    private function xmlElement(string $name, mixed $value, array $attributes = [], int $indentLevel = 1): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // Skip arrays - they should be handled separately by the caller
        if (is_array($value)) {
            return '';
        }

        // Standardized 4-space indentation per level
        $indent = str_repeat('    ', $indentLevel);
        $attrs = '';
        foreach ($attributes as $attrName => $attrValue) {
            if (is_array($attrValue)) {
                continue;
            }
            $attrs .= " {$attrName}=\"".htmlspecialchars((string) $attrValue, ENT_XML1, 'UTF-8').'"';
        }

        $escapedValue = htmlspecialchars((string) $value, ENT_XML1, 'UTF-8');

        return "{$indent}<{$name}{$attrs}>{$escapedValue}</{$name}>\n";
    }

    /**
     * Write content to file with hash-based optimization.
     * Computes hash of content and compares to stored hash to avoid file reads.
     *
     * @param  string  $path  Full path to write the file
     * @param  string  $content  Content to write
     * @param  StrmFileMapping|null  $mapping  Optional mapping to check/update hash
     * @return bool Success status
     */
    private function writeFileWithHash(string $path, string $content, $mapping = null): bool
    {
        try {
            // Ensure directory exists
            $dir = dirname($path);
            if (! is_dir($dir)) {
                if (! @mkdir($dir, 0755, true)) {
                    Log::error("NfoService: Failed to create directory: {$dir}");

                    return false;
                }
            }

            // OPTIMIZATION: Hash-based content comparison
            // Compute hash of new content (SHA-256 for security, but MD5 would be faster)
            $newHash = hash('sha256', $content);

            // If we have a mapping with a stored hash, compare hashes instead of reading file
            // Also verify the file actually exists - if it was deleted, we must rewrite it regardless of hash match
            if ($mapping && $mapping->nfo_hash === $newHash && file_exists($path)) {
                // Hash matches and file exists - content is identical, skip write
                return true;
            }

            // Fallback: If no mapping or hash doesn't match, check file directly
            // This handles cases where hash tracking is new or was reset
            if (! $mapping && file_exists($path)) {
                $existingContent = @file_get_contents($path);
                if ($existingContent === $content) {
                    // Content unchanged, but update hash for future optimization
                    if ($mapping) {
                        $mapping->nfo_hash = $newHash;
                        $mapping->save();
                    }

                    return true;
                }
            }

            // Content has changed (or file doesn't exist), write it
            $result = file_put_contents($path, $content);

            if ($result === false) {
                Log::error("NfoService: Failed to write file: {$path}");

                return false;
            }

            // Update the hash in the mapping for next time
            if ($mapping) {
                $mapping->nfo_hash = $newHash;
                $mapping->save();
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("NfoService: Error writing file: {$path} - {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Write content to file
     * Optimized to skip writing if the existing file has identical content.
     *
     * @deprecated Use writeFileWithHash() for better performance with hash tracking
     */
    private function writeFile(string $path, string $content): bool
    {
        try {
            // Ensure directory exists
            $dir = dirname($path);
            if (! is_dir($dir)) {
                if (! @mkdir($dir, 0755, true)) {
                    Log::error("NfoService: Failed to create directory: {$dir}");

                    return false;
                }
            }

            // Optimization: Skip write if content is identical to reduce disk I/O
            if (file_exists($path)) {
                $existingContent = @file_get_contents($path);
                if ($existingContent === $content) {
                    // Content unchanged, skip write
                    return true;
                }
            }

            $result = file_put_contents($path, $content);

            if ($result === false) {
                Log::error("NfoService: Failed to write file: {$path}");

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error("NfoService: Error writing file: {$path} - {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Get a scalar value from mixed input.
     * If an array is provided, extract and return the first element.
     * If an object is provided, return null.
     *
     * @param  mixed  $value  The value to extract from
     * @return mixed The scalar value, first array element, or null
     */
    private function getScalarValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return ! empty($value) ? reset($value) : null;
        }

        if (is_object($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Apply name filter patterns to a string
     *
     * @param  string  $name  The name to filter
     * @param  bool  $enabled  Whether name filtering is enabled
     * @param  array  $patterns  Array of patterns to remove from the name
     * @return string The filtered name
     */
    private function applyNameFilter(string $name, bool $enabled, array $patterns): string
    {
        if (! $enabled || empty($patterns)) {
            return $name;
        }

        foreach ($patterns as $pattern) {
            // Only process string patterns to prevent TypeError
            if (is_string($pattern) && $pattern !== '') {
                $name = str_replace($pattern, '', $name);
            }
        }

        return trim($name);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DVR NFO generation
    //
    // The DVR pipeline stores enriched metadata on DvrRecording.metadata as:
    //   - metadata.tmdb           (id, type [movie|tv], name, overview,
    //                              poster_url, backdrop_url, release_date|first_air_date,
    //                              vote_average, vote_count, genres[], runtime, ...)
    //   - metadata.tmdb_episode   (name, overview, still_path, air_date, vote_average, ...)
    //   - metadata.tvmaze         (fallback when TMDB unavailable)
    //   - metadata.tvmaze_episode (fallback episode payload)
    //
    // NFO files are written via the Storage facade onto the same disk that
    // holds the recording (alongside file_path), which keeps remote disks
    // (s3/sftp/etc.) working transparently.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Generate a movie.nfo (next to the recording file) for a VOD-style DvrRecording.
     */
    public function generateDvrMovieNfo(DvrRecording $recording, string $disk): bool
    {
        try {
            if (empty($recording->file_path)) {
                return false;
            }

            $metadata = $recording->metadata ?? [];
            $tmdb = is_array($metadata['tmdb'] ?? null) ? $metadata['tmdb'] : [];
            $useProxy = (bool) ($recording->dvrSetting?->use_proxy);

            $title = $this->getScalarValue($tmdb['name'] ?? $tmdb['title'] ?? $recording->title) ?? $recording->title;
            $plot = $this->getScalarValue($tmdb['overview'] ?? $recording->description ?? null);
            $releaseDate = $this->getScalarValue($tmdb['release_date'] ?? $tmdb['first_air_date'] ?? null);

            $xml = $this->startXml('movie');
            $xml .= $this->xmlElement('title', $title);
            $xml .= $this->xmlElement('originaltitle', $title);
            $xml .= $this->xmlElement('sorttitle', $title);

            if (! empty($plot)) {
                $xml .= $this->xmlElement('plot', $plot);
                $xml .= $this->xmlElement('outline', mb_substr((string) $plot, 0, 300));
            }

            if (! empty($releaseDate) && is_string($releaseDate)) {
                $xml .= $this->xmlElement('year', substr($releaseDate, 0, 4));
                $xml .= $this->xmlElement('premiered', $releaseDate);
            }

            if (! empty($tmdb['vote_average']) && is_scalar($tmdb['vote_average'])) {
                $xml .= $this->xmlElement('rating', $tmdb['vote_average']);
            }
            if (! empty($tmdb['vote_count']) && is_scalar($tmdb['vote_count'])) {
                $xml .= $this->xmlElement('votes', $tmdb['vote_count']);
            }

            if (! empty($tmdb['runtime']) && is_scalar($tmdb['runtime'])) {
                $xml .= $this->xmlElement('runtime', $tmdb['runtime']);
            } elseif (! empty($recording->duration_seconds)) {
                $xml .= $this->xmlElement('runtime', (int) round($recording->duration_seconds / 60));
            }

            if (! empty($tmdb['genres']) && is_array($tmdb['genres'])) {
                foreach ($tmdb['genres'] as $genre) {
                    $genreName = is_array($genre) ? ($genre['name'] ?? '') : $genre;
                    if (! empty($genreName)) {
                        $xml .= $this->xmlElement('genre', $genreName);
                    }
                }
            }

            $poster = $this->getScalarValue($tmdb['poster_url'] ?? $tmdb['poster_path'] ?? null);
            if (! empty($poster) && is_string($poster)) {
                $xml .= $this->xmlElement('thumb', $this->dvrImageUrl($poster, $useProxy), ['aspect' => 'poster']);
            }

            $backdrop = $this->getScalarValue($tmdb['backdrop_url'] ?? $tmdb['backdrop_path'] ?? null);
            if (! empty($backdrop) && is_string($backdrop)) {
                $xml .= $this->xmlElement('fanart', $this->dvrImageUrl($backdrop, $useProxy));
            }

            $tmdbId = $this->getScalarValue($tmdb['id'] ?? null);
            if (! empty($tmdbId)) {
                $xml .= $this->xmlElement('uniqueid', $tmdbId, ['type' => 'tmdb', 'default' => 'true']);
                $xml .= $this->xmlElement('tmdbid', $tmdbId);
            }

            $xml .= $this->endXml('movie');

            $nfoRelPath = $this->dvrNfoPath($recording->file_path);

            return $this->writeDvrFile($disk, $nfoRelPath, $xml);
        } catch (\Throwable $e) {
            Log::error("NfoService: Error generating DVR movie NFO for recording {$recording->id}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Generate an episodedetails NFO (next to the recording file) for a series-style DvrRecording.
     */
    public function generateDvrEpisodeNfo(DvrRecording $recording, string $disk): bool
    {
        try {
            if (empty($recording->file_path)) {
                return false;
            }

            $metadata = $recording->metadata ?? [];
            $tmdbShow = is_array($metadata['tmdb'] ?? null) ? $metadata['tmdb'] : [];
            $tmdbEp = is_array($metadata['tmdb_episode'] ?? null) ? $metadata['tmdb_episode'] : [];
            $tvmazeEp = is_array($metadata['tvmaze_episode'] ?? null) ? $metadata['tvmaze_episode'] : [];
            $useProxy = (bool) ($recording->dvrSetting?->use_proxy);

            $episodeTitle = $this->getScalarValue(
                $tmdbEp['name'] ?? $tvmazeEp['name'] ?? $recording->subtitle ?? $recording->title
            ) ?? $recording->title;

            $showTitle = $this->getScalarValue($tmdbShow['name'] ?? $recording->title) ?? $recording->title;
            $plot = $this->getScalarValue($tmdbEp['overview'] ?? $tvmazeEp['summary'] ?? $recording->description ?? null);
            $airDate = $this->getScalarValue($tmdbEp['air_date'] ?? $tvmazeEp['airdate'] ?? null);

            $xml = $this->startXml('episodedetails');
            $xml .= $this->xmlElement('title', $episodeTitle);
            $xml .= $this->xmlElement('showtitle', $showTitle);

            if (! empty($recording->season)) {
                $xml .= $this->xmlElement('season', $recording->season);
            }
            if (! empty($recording->episode)) {
                $xml .= $this->xmlElement('episode', $recording->episode);
            }

            if (! empty($plot)) {
                $xml .= $this->xmlElement('plot', strip_tags((string) $plot));
            }

            if (! empty($airDate) && is_string($airDate)) {
                $xml .= $this->xmlElement('aired', $airDate);
            }

            if (! empty($tmdbEp['vote_average']) && is_scalar($tmdbEp['vote_average'])) {
                $xml .= $this->xmlElement('rating', $tmdbEp['vote_average']);
            }

            if (! empty($recording->duration_seconds)) {
                $xml .= $this->xmlElement('runtime', (int) round($recording->duration_seconds / 60));
            }

            $still = $this->getScalarValue($tmdbEp['still_path'] ?? $tmdbEp['still_url'] ?? null);
            if (! empty($still) && is_string($still)) {
                $xml .= $this->xmlElement('thumb', $this->dvrImageUrl($still, $useProxy));
            }

            $tmdbEpId = $this->getScalarValue($tmdbEp['id'] ?? null);
            $tmdbShowId = $this->getScalarValue($tmdbShow['id'] ?? null);
            if (! empty($tmdbEpId)) {
                $xml .= $this->xmlElement('uniqueid', $tmdbEpId, ['type' => 'tmdb', 'default' => 'true']);
            } elseif (! empty($tmdbShowId)) {
                $xml .= $this->xmlElement('uniqueid', $tmdbShowId, ['type' => 'tmdb', 'default' => 'true']);
            }

            $xml .= $this->endXml('episodedetails');

            $nfoRelPath = $this->dvrNfoPath($recording->file_path);

            return $this->writeDvrFile($disk, $nfoRelPath, $xml);
        } catch (\Throwable $e) {
            Log::error("NfoService: Error generating DVR episode NFO for recording {$recording->id}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Generate a tvshow.nfo in the series folder containing the given DVR episode recording.
     * Idempotent — safe to call once per episode; rewrites only when content changed.
     */
    public function generateDvrShowNfo(DvrRecording $recording, string $disk): bool
    {
        try {
            if (empty($recording->file_path)) {
                return false;
            }

            $metadata = $recording->metadata ?? [];
            $tmdbShow = is_array($metadata['tmdb'] ?? null) ? $metadata['tmdb'] : [];
            $tvmazeShow = is_array($metadata['tvmaze'] ?? null) ? $metadata['tvmaze'] : [];
            $useProxy = (bool) ($recording->dvrSetting?->use_proxy);

            $showTitle = $this->getScalarValue(
                $tmdbShow['name'] ?? $tvmazeShow['name'] ?? $recording->title
            ) ?? $recording->title;

            $plot = $this->getScalarValue($tmdbShow['overview'] ?? $tvmazeShow['summary'] ?? null);
            $premiered = $this->getScalarValue($tmdbShow['first_air_date'] ?? $tvmazeShow['premiered'] ?? null);

            $xml = $this->startXml('tvshow');
            $xml .= $this->xmlElement('title', $showTitle);
            $xml .= $this->xmlElement('originaltitle', $showTitle);
            $xml .= $this->xmlElement('sorttitle', $showTitle);

            if (! empty($plot)) {
                $xml .= $this->xmlElement('plot', strip_tags((string) $plot));
                $xml .= $this->xmlElement('outline', mb_substr(strip_tags((string) $plot), 0, 300));
            }

            if (! empty($premiered) && is_string($premiered)) {
                $xml .= $this->xmlElement('year', substr($premiered, 0, 4));
                $xml .= $this->xmlElement('premiered', $premiered);
            }

            if (! empty($tmdbShow['vote_average']) && is_scalar($tmdbShow['vote_average'])) {
                $xml .= $this->xmlElement('rating', $tmdbShow['vote_average']);
            }

            if (! empty($tmdbShow['genres']) && is_array($tmdbShow['genres'])) {
                foreach ($tmdbShow['genres'] as $genre) {
                    $genreName = is_array($genre) ? ($genre['name'] ?? '') : $genre;
                    if (! empty($genreName)) {
                        $xml .= $this->xmlElement('genre', $genreName);
                    }
                }
            }

            $poster = $this->getScalarValue($tmdbShow['poster_url'] ?? $tmdbShow['poster_path'] ?? null);
            if (! empty($poster) && is_string($poster)) {
                $xml .= $this->xmlElement('thumb', $this->dvrImageUrl($poster, $useProxy), ['aspect' => 'poster']);
            }

            $backdrop = $this->getScalarValue($tmdbShow['backdrop_url'] ?? $tmdbShow['backdrop_path'] ?? null);
            if (! empty($backdrop) && is_string($backdrop)) {
                $xml .= $this->xmlElement('fanart', $this->dvrImageUrl($backdrop, $useProxy));
            }

            $tmdbId = $this->getScalarValue($tmdbShow['id'] ?? null);
            if (! empty($tmdbId)) {
                $xml .= $this->xmlElement('uniqueid', $tmdbId, ['type' => 'tmdb', 'default' => 'true']);
                $xml .= $this->xmlElement('tmdbid', $tmdbId);
            }

            $xml .= $this->endXml('tvshow');

            // tvshow.nfo lives in the series folder (the parent dir of the recording file).
            $nfoRelPath = rtrim(dirname($recording->file_path), '/').'/tvshow.nfo';

            return $this->writeDvrFile($disk, $nfoRelPath, $xml);
        } catch (\Throwable $e) {
            Log::error("NfoService: Error generating DVR tvshow NFO for recording {$recording->id}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Decide whether a DVR recording is series-shaped (has season/episode info or series_key).
     */
    public function isDvrRecordingSeries(DvrRecording $recording): bool
    {
        if (! empty($recording->series_key)) {
            return true;
        }
        if (! empty($recording->season) || ! empty($recording->episode)) {
            return true;
        }
        $type = $recording->metadata['tmdb']['type'] ?? null;

        return $type === 'tv';
    }

    /**
     * Convert a recording file_path (e.g. library/2025/Title/Title - S01E01.mp4) to its NFO sibling path.
     */
    private function dvrNfoPath(string $filePath): string
    {
        // Replace the file extension with .nfo regardless of container (mp4/mkv/ts).
        return preg_replace('/\.[A-Za-z0-9]+$/', '.nfo', $filePath) ?? ($filePath.'.nfo');
    }

    /**
     * Build the URL written into NFO image fields. When the playlist's DVR setting
     * has use_proxy enabled we route through LogoProxyController so Kodi/Jellyfin
     * pull through this app instead of TMDB directly.
     */
    private function dvrImageUrl(string $url, bool $useProxy): string
    {
        // Normalise bare TMDB paths to absolute URLs first.
        if (! str_starts_with($url, 'http')) {
            $url = 'https://image.tmdb.org/t/p/original'.$url;
        }

        if (! $useProxy) {
            return $url;
        }

        try {
            return LogoProxyController::generateProxyUrl($url);
        } catch (\Throwable $e) {
            Log::warning("NfoService: proxy URL generation failed, falling back to direct URL: {$e->getMessage()}");

            return $url;
        }
    }

    /**
     * Write an NFO file via the Storage facade so it lands on the same disk as the recording.
     * Skips the write when the destination already contains identical bytes.
     */
    private function writeDvrFile(string $disk, string $relPath, string $content): bool
    {
        try {
            $fs = Storage::disk($disk);

            if ($fs->exists($relPath)) {
                $existing = $fs->get($relPath);
                if ($existing === $content) {
                    return true;
                }
            }

            return $fs->put($relPath, $content);
        } catch (\Throwable $e) {
            Log::error("NfoService: Failed to write DVR NFO {$relPath} on disk {$disk}: {$e->getMessage()}");

            return false;
        }
    }
}
