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
use Throwable;

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
    public function generateSeriesNfo(Series $series, string $path, ?StrmFileMapping $mapping = null, bool $nameFilterEnabled = false, array $nameFilterPatterns = []): bool
    {
        try {
            $metadata = $series->metadata ?? [];

            $tmdbId = $this->getScalarValue($metadata['tmdb_id'] ?? $metadata['tmdb'] ?? null);
            $tvdbId = $this->getScalarValue($metadata['tvdb_id'] ?? $metadata['tvdb'] ?? null);
            $imdbId = $this->getScalarValue($metadata['imdb_id'] ?? $metadata['imdb'] ?? null);

            $xml = $this->startXml('tvshow');

            // Basic info - apply name filter if enabled
            $seriesName = $this->applyNameFilter($series->name, $nameFilterEnabled, $nameFilterPatterns);
            $xml .= $this->xmlElement('title', $seriesName);
            $xml .= $this->xmlElement('originaltitle', $seriesName);
            $xml .= $this->xmlElement('sorttitle', $seriesName);

            if (! empty($metadata['plot']) && is_string($metadata['plot'])) {
                $xml .= $this->xmlElement('plot', $metadata['plot']);
                $xml .= $this->xmlElement('outline', $metadata['plot']);
            }

            if (! empty($series->release_date) && is_string($series->release_date)) {
                $xml .= $this->xmlElement('year', substr($series->release_date, 0, 4));
                $xml .= $this->xmlElement('premiered', $series->release_date);
            }

            $this->appendXml($xml, 'rating', $metadata['vote_average'] ?? null);
            $this->appendXml($xml, 'votes', $metadata['vote_count'] ?? null);

            if (! empty($metadata['status']) && is_string($metadata['status'])) {
                $xml .= $this->xmlElement('status', $metadata['status']);
            }

            $this->appendGenres($xml, $metadata['genres'] ?? null);
            $this->appendNamedList($xml, 'studio', $metadata['networks'] ?? null);

            $this->appendImage($xml, 'thumb', $metadata['poster_path'] ?? null, useProxy: false, attrs: ['aspect' => 'poster']);
            $this->appendImage($xml, 'fanart', $metadata['backdrop_path'] ?? null);

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

            if (! is_dir($path) && ! @mkdir($path, 0755, true)) {
                Log::error("NfoService: Failed to create directory: {$path}");

                return false;
            }

            $filePath = rtrim($path, '/').'/tvshow.nfo';

            return $this->writeFileWithHash($filePath, $xml, $mapping);
        } catch (Throwable $e) {
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
    public function generateEpisodeNfo(Episode $episode, Series $series, string $filePath, ?StrmFileMapping $mapping = null, bool $nameFilterEnabled = false, array $nameFilterPatterns = []): bool
    {
        try {
            $info = $episode->info ?? [];
            $metadata = $series->metadata ?? [];

            $tmdbId = $this->getScalarValue($info['tmdb_id'] ?? $info['tmdb'] ?? $metadata['tmdb_id'] ?? $metadata['tmdb'] ?? null);
            $tvdbId = $this->getScalarValue($metadata['tvdb_id'] ?? $metadata['tvdb'] ?? null);
            $imdbId = $this->getScalarValue($metadata['imdb_id'] ?? $metadata['imdb'] ?? null);

            $xml = $this->startXml('episodedetails');

            // Basic info - apply name filter if enabled
            $episodeTitle = $this->applyNameFilter($episode->title, $nameFilterEnabled, $nameFilterPatterns);
            $seriesName = $this->applyNameFilter($series->name, $nameFilterEnabled, $nameFilterPatterns);
            $xml .= $this->xmlElement('title', $episodeTitle);
            $xml .= $this->xmlElement('showtitle', $seriesName);
            $xml .= $this->xmlElement('season', $episode->season);
            $xml .= $this->xmlElement('episode', $episode->episode_num);

            $this->appendXml($xml, 'plot', $info['plot'] ?? null);
            $this->appendXml($xml, 'aired', $info['air_date'] ?? $info['releasedate'] ?? null);
            $this->appendXml($xml, 'rating', $info['vote_average'] ?? $info['rating'] ?? null);

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
                $xml .= $this->xmlElement('thumb', $this->tmdbImageUrl($stillPath));
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

            $nfoPath = preg_replace('/\.strm$/i', '.nfo', $filePath);

            return $this->writeFileWithHash($nfoPath, $xml, $mapping);
        } catch (Throwable $e) {
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
    public function generateMovieNfo(Channel $channel, string $filePath, ?StrmFileMapping $mapping = null, array $options = []): bool
    {
        try {
            $info = $channel->info ?? [];
            $movieData = $channel->movie_data ?? [];

            $nameFilterEnabled = $options['name_filter_enabled'] ?? false;
            $nameFilterPatterns = $options['name_filter_patterns'] ?? [];

            $tmdbId = $this->getScalarValue($info['tmdb_id'] ?? $info['tmdb'] ?? $movieData['tmdb_id'] ?? $movieData['tmdb'] ?? null);
            $imdbId = $this->getScalarValue($info['imdb_id'] ?? $info['imdb'] ?? $movieData['imdb_id'] ?? $movieData['imdb'] ?? null);

            $xml = $this->startXml('movie');

            // Basic info - apply name filter if enabled
            $title = $this->applyNameFilter($channel->title_custom ?? $channel->title, $nameFilterEnabled, $nameFilterPatterns);
            $xml .= $this->xmlElement('title', $title);
            $xml .= $this->xmlElement('originaltitle', $title);
            $xml .= $this->xmlElement('sorttitle', $title);

            $plot = $info['plot'] ?? $movieData['plot'] ?? $movieData['description'] ?? null;
            if (! empty($plot)) {
                $xml .= $this->xmlElement('plot', $plot);
                $xml .= $this->xmlElement('outline', mb_substr($plot, 0, 300));
            }

            $year = $channel->year ?? $info['year'] ?? $movieData['releasedate'] ?? null;
            if (! empty($year)) {
                if (strlen($year) > 4) {
                    $year = substr($year, 0, 4);
                }
                $xml .= $this->xmlElement('year', $year);
            }

            // Rating (with optional 5-based → 10-based conversion)
            $rating = $info['vote_average'] ?? $info['rating'] ?? $movieData['rating'] ?? $movieData['rating_5based'] ?? null;
            if (! empty($rating)) {
                if (is_numeric($rating) && $rating <= 5 && isset($movieData['rating_5based'])) {
                    $rating *= 2;
                }
                $xml .= $this->xmlElement('rating', $rating);
            }

            // Runtime (in minutes)
            $runtime = $info['runtime'] ?? $movieData['duration_secs'] ?? null;
            if (! empty($runtime)) {
                if ($runtime > 300) {
                    $runtime = round($runtime / 60);
                }
                $xml .= $this->xmlElement('runtime', $runtime);
            }

            $this->appendGenres($xml, $info['genres'] ?? $movieData['genre'] ?? null);
            $this->appendXml($xml, 'director', $info['director'] ?? $movieData['director'] ?? null);

            // Cast (rich actor blocks with optional role/thumb)
            $cast = $info['cast'] ?? $movieData['cast'] ?? null;
            if (! empty($cast)) {
                $castList = is_string($cast) ? array_map('trim', explode(',', $cast)) : $cast;
                foreach ($castList as $actor) {
                    $actorName = is_array($actor) ? ($actor['name'] ?? '') : $actor;
                    if (empty($actorName)) {
                        continue;
                    }
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

            $this->appendImage($xml, 'thumb', $info['poster_path'] ?? $movieData['cover_big'] ?? $movieData['movie_image'] ?? null, useProxy: false, attrs: ['aspect' => 'poster']);
            $this->appendImage($xml, 'fanart', $info['backdrop_path'] ?? $movieData['backdrop_path'] ?? null);

            // Country (mixed string|array of strings|array of {name|iso_3166_1})
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

            // Unique IDs
            if (! empty($tmdbId)) {
                $xml .= $this->xmlElement('uniqueid', $tmdbId, ['type' => 'tmdb', 'default' => 'true']);
                $xml .= $this->xmlElement('tmdbid', $tmdbId);
            }
            if (! empty($imdbId)) {
                $xml .= $this->xmlElement('uniqueid', $imdbId, ['type' => 'imdb']);
                $xml .= $this->xmlElement('imdbid', $imdbId);
            }

            $xml .= $this->endXml('movie');

            $nfoPath = preg_replace('/\.strm$/i', '.nfo', $filePath);

            return $this->writeFileWithHash($nfoPath, $xml, $mapping);
        } catch (Throwable $e) {
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
     */
    private function xmlElement(string $name, mixed $value, array $attributes = [], int $indentLevel = 1): string
    {
        if ($value === null || $value === '' || is_array($value)) {
            return '';
        }

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
     * Append an XML element to $xml when the value is a non-empty scalar.
     * No-op for null, empty string, arrays, or objects.
     */
    private function appendXml(string &$xml, string $tag, mixed $value, array $attrs = []): void
    {
        if (! empty($value) && is_scalar($value)) {
            $xml .= $this->xmlElement($tag, $value, $attrs);
        }
    }

    /**
     * Append a single image XML element after normalising the URL.
     * Supports both bare TMDB paths and absolute URLs, with optional LogoProxy routing.
     */
    private function appendImage(string &$xml, string $tag, mixed $url, bool $useProxy = false, array $attrs = []): void
    {
        $url = $this->getScalarValue($url);
        if (empty($url) || ! is_string($url)) {
            return;
        }

        $resolved = $useProxy ? $this->dvrImageUrl($url, true) : $this->tmdbImageUrl($url);
        $xml .= $this->xmlElement($tag, $resolved, $attrs);
    }

    /**
     * Append <genre> entries from an array, comma-separated string, or array of {name}.
     */
    private function appendGenres(string &$xml, mixed $genres): void
    {
        if (empty($genres)) {
            return;
        }
        if (is_string($genres)) {
            $genres = array_map('trim', explode(',', $genres));
        }
        if (! is_array($genres)) {
            return;
        }
        foreach ($genres as $genre) {
            $name = is_array($genre) ? ($genre['name'] ?? '') : $genre;
            if (! empty($name)) {
                $xml .= $this->xmlElement('genre', $name);
            }
        }
    }

    /**
     * Append a list of items (arrays of {name} or scalars) under the given tag.
     * Used for studios/networks-style fields.
     */
    private function appendNamedList(string &$xml, string $tag, mixed $items): void
    {
        if (empty($items) || ! is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            $name = is_array($item) ? ($item['name'] ?? '') : $item;
            if (! empty($name)) {
                $xml .= $this->xmlElement($tag, $name);
            }
        }
    }

    /**
     * Normalise a TMDB image path to an absolute URL. Pass-through for full URLs.
     */
    private function tmdbImageUrl(string $url): string
    {
        return str_starts_with($url, 'http')
            ? $url
            : 'https://image.tmdb.org/t/p/original'.$url;
    }

    /**
     * Write content to file with hash-based optimization.
     * Computes hash of content and compares to stored hash to avoid file reads.
     */
    private function writeFileWithHash(string $path, string $content, ?StrmFileMapping $mapping = null): bool
    {
        try {
            $dir = dirname($path);
            if (! is_dir($dir) && ! @mkdir($dir, 0755, true)) {
                Log::error("NfoService: Failed to create directory: {$dir}");

                return false;
            }

            $newHash = hash('sha256', $content);

            // If we have a mapping with a stored hash and the file still exists, skip the write.
            if ($mapping && $mapping->nfo_hash === $newHash && file_exists($path)) {
                return true;
            }

            // Fallback: no mapping yet — compare bytes directly to avoid an unnecessary write.
            if (! $mapping && file_exists($path) && @file_get_contents($path) === $content) {
                return true;
            }

            if (file_put_contents($path, $content) === false) {
                Log::error("NfoService: Failed to write file: {$path}");

                return false;
            }

            if ($mapping) {
                $mapping->nfo_hash = $newHash;
                $mapping->save();
            }

            return true;
        } catch (Throwable $e) {
            Log::error("NfoService: Error writing file: {$path} - {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Get a scalar value from mixed input.
     * If an array is provided, extract and return the first element.
     * If an object is provided, return null.
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
            $tmdb = $this->metaArray($metadata, 'tmdb');
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

            $this->appendXml($xml, 'rating', $tmdb['vote_average'] ?? null);
            $this->appendXml($xml, 'votes', $tmdb['vote_count'] ?? null);

            if (! empty($tmdb['runtime']) && is_scalar($tmdb['runtime'])) {
                $xml .= $this->xmlElement('runtime', $tmdb['runtime']);
            } elseif (! empty($recording->duration_seconds)) {
                $xml .= $this->xmlElement('runtime', (int) round($recording->duration_seconds / 60));
            }

            $this->appendGenres($xml, $tmdb['genres'] ?? null);
            $this->appendImage($xml, 'thumb', $tmdb['poster_url'] ?? $tmdb['poster_path'] ?? null, $useProxy, ['aspect' => 'poster']);
            $this->appendImage($xml, 'fanart', $tmdb['backdrop_url'] ?? $tmdb['backdrop_path'] ?? null, $useProxy);

            $tmdbId = $this->getScalarValue($tmdb['id'] ?? null);
            if (! empty($tmdbId)) {
                $xml .= $this->xmlElement('uniqueid', $tmdbId, ['type' => 'tmdb', 'default' => 'true']);
                $xml .= $this->xmlElement('tmdbid', $tmdbId);
            }

            $xml .= $this->endXml('movie');

            return $this->writeDvrFile($disk, $this->dvrNfoPath($recording->file_path), $xml);
        } catch (Throwable $e) {
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
            $tmdbShow = $this->metaArray($metadata, 'tmdb');
            $tmdbEp = $this->metaArray($metadata, 'tmdb_episode');
            $tvmazeEp = $this->metaArray($metadata, 'tvmaze_episode');
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

            $this->appendXml($xml, 'season', $recording->season);
            $this->appendXml($xml, 'episode', $recording->episode);

            if (! empty($plot)) {
                $xml .= $this->xmlElement('plot', strip_tags((string) $plot));
            }

            if (! empty($airDate) && is_string($airDate)) {
                $xml .= $this->xmlElement('aired', $airDate);
            }

            $this->appendXml($xml, 'rating', $tmdbEp['vote_average'] ?? null);

            if (! empty($recording->duration_seconds)) {
                $xml .= $this->xmlElement('runtime', (int) round($recording->duration_seconds / 60));
            }

            $this->appendImage($xml, 'thumb', $tmdbEp['still_path'] ?? $tmdbEp['still_url'] ?? null, $useProxy);

            $tmdbEpId = $this->getScalarValue($tmdbEp['id'] ?? null);
            $tmdbShowId = $this->getScalarValue($tmdbShow['id'] ?? null);
            if (! empty($tmdbEpId)) {
                $xml .= $this->xmlElement('uniqueid', $tmdbEpId, ['type' => 'tmdb', 'default' => 'true']);
            } elseif (! empty($tmdbShowId)) {
                $xml .= $this->xmlElement('uniqueid', $tmdbShowId, ['type' => 'tmdb', 'default' => 'true']);
            }

            $xml .= $this->endXml('episodedetails');

            return $this->writeDvrFile($disk, $this->dvrNfoPath($recording->file_path), $xml);
        } catch (Throwable $e) {
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
            $tmdbShow = $this->metaArray($metadata, 'tmdb');
            $tvmazeShow = $this->metaArray($metadata, 'tvmaze');
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
                $cleanPlot = strip_tags((string) $plot);
                $xml .= $this->xmlElement('plot', $cleanPlot);
                $xml .= $this->xmlElement('outline', mb_substr($cleanPlot, 0, 300));
            }

            if (! empty($premiered) && is_string($premiered)) {
                $xml .= $this->xmlElement('year', substr($premiered, 0, 4));
                $xml .= $this->xmlElement('premiered', $premiered);
            }

            $this->appendXml($xml, 'rating', $tmdbShow['vote_average'] ?? null);
            $this->appendGenres($xml, $tmdbShow['genres'] ?? null);
            $this->appendImage($xml, 'thumb', $tmdbShow['poster_url'] ?? $tmdbShow['poster_path'] ?? null, $useProxy, ['aspect' => 'poster']);
            $this->appendImage($xml, 'fanart', $tmdbShow['backdrop_url'] ?? $tmdbShow['backdrop_path'] ?? null, $useProxy);

            $tmdbId = $this->getScalarValue($tmdbShow['id'] ?? null);
            if (! empty($tmdbId)) {
                $xml .= $this->xmlElement('uniqueid', $tmdbId, ['type' => 'tmdb', 'default' => 'true']);
                $xml .= $this->xmlElement('tmdbid', $tmdbId);
            }

            $xml .= $this->endXml('tvshow');

            // tvshow.nfo lives in the series folder (the parent dir of the recording file).
            $nfoRelPath = rtrim(dirname($recording->file_path), '/').'/tvshow.nfo';

            return $this->writeDvrFile($disk, $nfoRelPath, $xml);
        } catch (Throwable $e) {
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

        return ($recording->metadata['tmdb']['type'] ?? null) === 'tv';
    }

    /**
     * Extract a nested array from a metadata payload, returning [] when missing or non-array.
     */
    private function metaArray(array $metadata, string $key): array
    {
        return is_array($metadata[$key] ?? null) ? $metadata[$key] : [];
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
        $url = $this->tmdbImageUrl($url);

        if (! $useProxy) {
            return $url;
        }

        try {
            return LogoProxyController::generateProxyUrl($url);
        } catch (Throwable $e) {
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

            if ($fs->exists($relPath) && $fs->get($relPath) === $content) {
                return true;
            }

            return $fs->put($relPath, $content);
        } catch (Throwable $e) {
            Log::error("NfoService: Failed to write DVR NFO {$relPath} on disk {$disk}: {$e->getMessage()}");

            return false;
        }
    }
}
