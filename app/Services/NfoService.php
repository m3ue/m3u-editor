<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Series;
use Illuminate\Support\Facades\Log;

class NfoService
{
    /**
     * Generate a tvshow.nfo file for a series (Kodi/Emby/Jellyfin format)
     */
    public function generateSeriesNfo(Series $series, string $path): bool
    {
        try {
            $metadata = $series->metadata ?? [];

            // Get IDs (ensure scalar values)
            $tmdbId = $this->getScalarValue($metadata['tmdb_id'] ?? $metadata['tmdb'] ?? null);
            $tvdbId = $this->getScalarValue($metadata['tvdb_id'] ?? $metadata['tvdb'] ?? null);
            $imdbId = $this->getScalarValue($metadata['imdb_id'] ?? $metadata['imdb'] ?? null);

            // Build the NFO XML
            $xml = $this->startXml('tvshow');

        // Basic info
        $xml .= $this->xmlElement('title', $series->name);
        $xml .= $this->xmlElement('originaltitle', $series->name);
        $xml .= $this->xmlElement('sorttitle', $series->name);

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
        if (! empty($metadata['poster_path'])) {
            $posterUrl = 'https://image.tmdb.org/t/p/original' . $metadata['poster_path'];
            $xml .= $this->xmlElement('thumb', $posterUrl, ['aspect' => 'poster']);
        }

        // Backdrop
        if (! empty($metadata['backdrop_path'])) {
            $backdropUrl = 'https://image.tmdb.org/t/p/original' . $metadata['backdrop_path'];
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

        $filePath = rtrim($path, '/') . '/tvshow.nfo';

        return $this->writeFile($filePath, $xml);
        } catch (\Throwable $e) {
            Log::error("NfoService: Error generating series NFO for {$series->name}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Generate an episode.nfo file for a series episode
     */
    public function generateEpisodeNfo(Episode $episode, Series $series, string $filePath): bool
    {
        try {
        $info = $episode->info ?? [];
        $metadata = $series->metadata ?? [];

        // Get IDs
        $tmdbId = $info['tmdb_id'] ?? $info['tmdb'] ?? $metadata['tmdb_id'] ?? $metadata['tmdb'] ?? null;
        $tvdbId = $metadata['tvdb_id'] ?? $metadata['tvdb'] ?? null;
        $imdbId = $metadata['imdb_id'] ?? $metadata['imdb'] ?? null;

        // Build the NFO XML
        $xml = $this->startXml('episodedetails');

        // Basic info
        $xml .= $this->xmlElement('title', $episode->title);
        $xml .= $this->xmlElement('showtitle', $series->name);

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
        if (! empty($info['still_path'])) {
            $thumbUrl = 'https://image.tmdb.org/t/p/original' . $info['still_path'];
            $xml .= $this->xmlElement('thumb', $thumbUrl);
        } elseif (! empty($info['movie_image'])) {
            $xml .= $this->xmlElement('thumb', $info['movie_image']);
        }

        // Unique IDs
        if (! empty($info['tmdb_episode_id'])) {
            $xml .= $this->xmlElement('uniqueid', $info['tmdb_episode_id'], ['type' => 'tmdb', 'default' => 'true']);
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

        return $this->writeFile($nfoPath, $xml);
        } catch (\Throwable $e) {
            Log::error("NfoService: Error generating episode NFO for {$episode->title}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Generate a movie.nfo file for a VOD (Kodi/Emby/Jellyfin format)
     */
    public function generateMovieNfo(Channel $channel, string $filePath): bool
    {
        try {
            $info = $channel->info ?? [];
            $movieData = $channel->movie_data ?? [];

            // Get IDs from multiple sources (ensure scalar values)
            $tmdbId = $this->getScalarValue($info['tmdb_id'] ?? $info['tmdb'] ?? $movieData['tmdb_id'] ?? $movieData['tmdb'] ?? null);
            $imdbId = $this->getScalarValue($info['imdb_id'] ?? $info['imdb'] ?? $movieData['imdb_id'] ?? $movieData['imdb'] ?? null);

            // Build the NFO XML
        $xml = $this->startXml('movie');

        // Basic info
        $title = $channel->title_custom ?? $channel->title;
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
                        $xml .= $this->xmlElement('thumb', 'https://image.tmdb.org/t/p/w185' . $actor['profile_path'], [], 2);
                    }
                    $xml .= "    </actor>\n";
                }
            }
        }

        // Poster
        $poster = $info['poster_path'] ?? $movieData['cover_big'] ?? $movieData['movie_image'] ?? null;
        if (! empty($poster)) {
            $posterUrl = str_starts_with($poster, 'http')
                ? $poster
                : 'https://image.tmdb.org/t/p/original' . $poster;
            $xml .= $this->xmlElement('thumb', $posterUrl, ['aspect' => 'poster']);
        }

        // Backdrop
        $backdrop = $info['backdrop_path'] ?? $movieData['backdrop_path'] ?? null;
        if (! empty($backdrop)) {
            $backdropUrl = str_starts_with($backdrop, 'http')
                ? $backdrop
                : 'https://image.tmdb.org/t/p/original' . $backdrop;
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

        return $this->writeFile($nfoPath, $xml);
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
        $nfoPath = rtrim($seriesPath, '/') . '/tvshow.nfo';

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
            $attrs .= " {$attrName}=\"" . htmlspecialchars((string) $attrValue, ENT_XML1, 'UTF-8') . '"';
        }

        $escapedValue = htmlspecialchars((string) $value, ENT_XML1, 'UTF-8');

        return "{$indent}<{$name}{$attrs}>{$escapedValue}</{$name}>\n";
    }

    /**
     * Write content to file
     * Optimized to skip writing if the existing file has identical content.
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
     * Get a scalar value from mixed input, returning null if it's an array or object
     */
    private function getScalarValue(mixed $value): mixed
    {
        if (is_array($value) || is_object($value)) {
            return null;
        }

        return $value;
    }
}
