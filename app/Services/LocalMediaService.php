<?php

namespace App\Services;

use App\Http\Controllers\MediaServerProxyController;
use App\Interfaces\MediaServer;
use App\Models\MediaServerIntegration;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * LocalMediaService - Service for local media file integration
 *
 * Handles scanning and managing local media files mounted to the container.
 * Supports movies and TV shows with metadata extraction from filenames.
 */
class LocalMediaService implements MediaServer
{
    protected MediaServerIntegration $integration;

    /**
     * Common video file extensions.
     */
    protected array $defaultVideoExtensions = [
        'mp4', 'mkv', 'avi', 'mov', 'wmv', 'flv', 'webm',
        'm4v', 'mpeg', 'mpg', 'ts', 'm2ts', 'mts', 'vob',
    ];

    /**
     * Regex patterns for parsing movie filenames.
     * Matches: "Movie Title (2024).mkv" or "Movie.Title.2024.1080p.BluRay.mkv"
     */
    protected array $moviePatterns = [
        // Standard format: "Movie Title (2024).ext"
        '/^(?<title>.+?)\s*\((?<year>\d{4})\)\s*(?:\[.*?\])?\s*\.(?<ext>\w+)$/i',
        // Dot-separated: "Movie.Title.2024.quality.source.ext"
        '/^(?<title>.+?)\.(?<year>(?:19|20)\d{2})\..*\.(?<ext>\w+)$/i',
        // Simple: "Movie Title.ext" (no year)
        '/^(?<title>.+?)\.(?<ext>\w+)$/i',
    ];

    /**
     * Regex patterns for parsing TV show episode filenames.
     * Matches: "Show Name S01E02.mkv" or "Show.Name.S01E02.Episode.Title.mkv"
     */
    protected array $episodePatterns = [
        // Standard: "Show Name S01E02 - Episode Title.ext"
        '/^(?<show>.+?)\s*[Ss](?<season>\d{1,2})[Ee](?<episode>\d{1,2})(?:\s*-\s*(?<title>.+?))?\.(?<ext>\w+)$/i',
        // Dot-separated: "Show.Name.S01E02.Episode.Title.ext"
        '/^(?<show>.+?)[.\s]+[Ss](?<season>\d{1,2})[Ee](?<episode>\d{1,2})[.\s]*(?<title>.+?)?\.(?<ext>\w+)$/i',
        // Season x Episode: "Show Name 1x02.ext"
        '/^(?<show>.+?)\s*(?<season>\d{1,2})x(?<episode>\d{1,2})(?:\s*-?\s*(?<title>.+?))?\.(?<ext>\w+)$/i',
        // Folder-based: assumes parent folder is season folder
        '/^(?<episode>\d{1,2})\s*[-.]?\s*(?<title>.+?)?\.(?<ext>\w+)$/i',
    ];

    /**
     * Create a new LocalMediaService instance.
     */
    public function __construct(MediaServerIntegration $integration)
    {
        $this->integration = $integration;
    }

    /**
     * Static factory method for convenience.
     */
    public static function make(MediaServerIntegration $integration): self
    {
        return new self($integration);
    }

    /**
     * Test connection - for local media, this validates that paths are accessible.
     *
     * @return array{success: bool, message: string, paths_found?: int, total_files?: int}
     */
    public function testConnection(): array
    {
        try {
            $paths = $this->integration->local_media_paths ?? [];

            if (empty($paths)) {
                return [
                    'success' => false,
                    'message' => 'No media paths configured. Please add at least one media library path.',
                ];
            }

            $validPaths = 0;
            $totalFiles = 0;
            $errors = [];

            foreach ($paths as $pathConfig) {
                $path = $pathConfig['path'] ?? '';

                if (empty($path)) {
                    continue;
                }

                if (! File::exists($path)) {
                    $errors[] = "Path not found: {$path}";

                    continue;
                }

                if (! File::isDirectory($path)) {
                    $errors[] = "Not a directory: {$path}";

                    continue;
                }

                if (! File::isReadable($path)) {
                    $errors[] = "Path not readable: {$path}";

                    continue;
                }

                $validPaths++;

                // Quick count of video files
                $files = $this->scanDirectoryForVideoFiles($path, false);
                $totalFiles += count($files);
            }

            if ($validPaths === 0) {
                return [
                    'success' => false,
                    'message' => 'No valid paths found. '.implode(' ', $errors),
                ];
            }

            $flatStructureWarnings = $this->getSeriesPathWarnings();

            $message = "Found {$validPaths} valid path(s) with {$totalFiles} video file(s)";
            if (! empty($errors)) {
                $message .= '. Warnings: '.implode('; ', $errors);
            }
            if (! empty($flatStructureWarnings)) {
                $message .= '. '.implode(' ', $flatStructureWarnings);
            }

            return [
                'success' => true,
                'message' => $message,
                'server_name' => 'Local Media',
                'version' => '1.0',
                'paths_found' => $validPaths,
                'total_files' => $totalFiles,
                'flat_structure_warnings' => $flatStructureWarnings,
            ];
        } catch (Exception $e) {
            Log::error('LocalMediaService: Connection test failed', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Error testing paths: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Fetch available libraries - returns configured local paths as libraries.
     *
     * @return Collection<int, array{id: string, name: string, type: string, item_count: int, path: string}>
     */
    public function fetchLibraries(): Collection
    {
        $paths = $this->integration->local_media_paths ?? [];
        $libraries = [];

        foreach ($paths as $index => $pathConfig) {
            $path = $pathConfig['path'] ?? '';
            $name = $pathConfig['name'] ?? basename($path) ?: "Library {$index}";
            $type = $pathConfig['type'] ?? 'movies';

            if (empty($path) || ! File::exists($path)) {
                continue;
            }

            // Count files in the directory
            $files = $this->scanDirectoryForVideoFiles($path, false);
            $itemCount = count($files);

            $libraries[] = [
                'id' => md5($path),
                'name' => $name,
                'type' => $type,
                'item_count' => $itemCount,
                'path' => $path,
            ];
        }

        return collect($libraries);
    }

    /**
     * Fetch all movies from configured movie paths.
     *
     * @return Collection<int, array>
     */
    public function fetchMovies(): Collection
    {
        $movies = collect();
        $paths = $this->integration->getLocalMediaPathsForType('movies');

        foreach ($paths as $pathConfig) {
            $path = $pathConfig['path'] ?? '';

            if (empty($path) || ! File::exists($path)) {
                continue;
            }

            $files = $this->scanDirectoryForVideoFiles(
                $path,
                $this->integration->scan_recursive
            );

            foreach ($files as $file) {
                $movieData = $this->parseMovieFile($file);
                if ($movieData) {
                    $movies->push($movieData);
                }
            }
        }

        return $movies;
    }

    /**
     * Fetch all series from configured TV show paths.
     *
     * @return Collection<int, array>
     */
    public function fetchSeries(): Collection
    {
        $series = collect();
        $paths = $this->integration->getLocalMediaPathsForType('tvshows');
        $seriesMap = [];

        foreach ($paths as $pathConfig) {
            $path = $pathConfig['path'] ?? '';

            if (empty($path) || ! File::exists($path)) {
                continue;
            }

            // For TV shows, we expect a folder structure:
            // /path/Show Name/Season 01/Episode.mkv
            // or /path/Show Name/S01E01.mkv
            $this->scanSeriesDirectory($path, $seriesMap);
        }

        // Convert series map to collection
        foreach ($seriesMap as $seriesData) {
            $series->push($seriesData);
        }

        return $series;
    }

    /**
     * Fetch detailed series information.
     */
    public function fetchSeriesDetails(string $seriesId): ?array
    {
        // For local media, the series data is already complete from fetchSeries
        // This would be where we could add TMDB/TVDB lookup in the future
        return null;
    }

    /**
     * Fetch seasons for a series.
     *
     * @return Collection<int, array>
     */
    public function fetchSeasons(string $seriesId): Collection
    {
        // Seasons are included in the series data structure
        // This method scans for seasons within a series folder
        $paths = $this->integration->getLocalMediaPathsForType('tvshows');
        $seasons = collect();

        foreach ($paths as $pathConfig) {
            $basePath = $pathConfig['path'] ?? '';
            $seriesPath = null;

            // Find the series folder by ID (which is md5 of path)
            if (File::exists($basePath)) {
                foreach (File::directories($basePath) as $dir) {
                    if (md5($dir) === $seriesId) {
                        $seriesPath = $dir;
                        break;
                    }
                }
            }

            if ($seriesPath) {
                $seasons = $this->scanSeasonsInSeries($seriesPath);
                break;
            }
        }

        return $seasons;
    }

    /**
     * Fetch episodes for a series/season.
     *
     * @return Collection<int, array>
     */
    public function fetchEpisodes(string $seriesId, ?string $seasonId = null): Collection
    {
        $episodes = collect();
        $paths = $this->integration->getLocalMediaPathsForType('tvshows');

        foreach ($paths as $pathConfig) {
            $basePath = $pathConfig['path'] ?? '';

            if (! File::exists($basePath)) {
                continue;
            }

            // Find series folder
            foreach (File::directories($basePath) as $seriesDir) {
                if (md5($seriesDir) !== $seriesId) {
                    continue;
                }

                // Scan for episodes
                $files = $this->scanDirectoryForVideoFiles($seriesDir, true);

                foreach ($files as $file) {
                    $episodeData = $this->parseEpisodeFile($file, basename($seriesDir));

                    if ($episodeData) {
                        // Filter by season if specified
                        if ($seasonId !== null) {
                            $seasonNumber = $this->resolveSeasonNumber($seriesDir, $seasonId);
                            if ($seasonNumber !== null && ($episodeData['ParentIndexNumber'] ?? 0) !== $seasonNumber) {
                                continue;
                            }
                        }
                        $episodes->push($episodeData);
                    }
                }
            }
        }

        return $episodes;
    }

    /**
     * Get the stream URL for an item - returns a public proxy URL.
     *
     * Uses the same pattern as Emby/Jellyfin to return a public URL that
     * goes through MediaServerProxyController, which supports Range requests
     * for video seeking.
     */
    public function getStreamUrl(string $itemId, string $container = 'ts'): string
    {
        // Use proxy URL like Emby/Jellyfin - supports Range requests for seeking
        return MediaServerProxyController::generateLocalMediaStreamUrl(
            $this->integration->id,
            $itemId
        );
    }

    /**
     * Get the direct stream URL.
     *
     * For network broadcasts, returns an HTTP URL that the m3u-proxy can stream from.
     * The m3u-proxy requires HTTP/HTTPS URLs, not file paths.
     */
    public function getDirectStreamUrl(Request $request, string $itemId, string $container = 'ts', array $transcodeOptions = []): string
    {
        // For network broadcasts and other HTTP-based streaming, return the public proxy URL
        // The m3u-proxy service requires HTTP/HTTPS URLs, not local file paths
        return MediaServerProxyController::generateLocalMediaStreamUrl(
            $this->integration->id,
            $itemId
        );
    }

    /**
     * Get image URL - for local media, we don't have images unless we fetch from TMDB.
     */
    public function getImageUrl(string $itemId, string $imageType = 'Primary'): string
    {
        // Return empty or a placeholder - metadata enrichment would add real images
        return '';
    }

    /**
     * Get direct image URL.
     */
    public function getDirectImageUrl(string $itemId, string $imageType = 'Primary'): string
    {
        return '';
    }

    /**
     * Extract genres from an item.
     */
    public function extractGenres(array $item): array
    {
        $genres = $item['Genres'] ?? [];

        if (empty($genres)) {
            return ['Uncategorized'];
        }

        if ($this->integration->genre_handling === 'primary') {
            return [reset($genres)];
        }

        return $genres;
    }

    /**
     * Get the container extension from the item.
     */
    public function getContainerExtension(array $item): string
    {
        return $item['Container'] ?? $item['container'] ?? 'mp4';
    }

    /**
     * Convert ticks to seconds (not applicable for local media).
     */
    public function ticksToSeconds(?int $ticks): ?int
    {
        if ($ticks === null) {
            return null;
        }

        // Standard conversion: 10,000,000 ticks = 1 second
        return (int) ($ticks / 10000000);
    }

    /**
     * Refresh library - triggers a rescan of local directories.
     *
     * @return array{success: bool, message: string}
     */
    public function refreshLibrary(): array
    {
        // For local media, "refresh" means rescanning the directories
        $result = $this->testConnection();

        if ($result['success']) {
            return [
                'success' => true,
                'message' => 'Local media paths rescanned successfully. '.$result['message'],
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to rescan: '.$result['message'],
        ];
    }

    /**
     * Resolve a season's IndexNumber from its md5-based ID.
     *
     * Season IDs for local media are md5 hashes of directory paths.
     * This method scans the series directory to find the matching season
     * and returns its actual season number.
     *
     * @param  string  $seriesDir  Path to the series directory
     * @param  string  $seasonId  The md5-based season ID
     * @return int|null The season number, or null if not found
     */
    protected function resolveSeasonNumber(string $seriesDir, string $seasonId): ?int
    {
        $seasons = $this->scanSeasonsInSeries($seriesDir);

        foreach ($seasons as $season) {
            if ($season['Id'] === $seasonId) {
                return $season['IndexNumber'];
            }
        }

        return null;
    }

    /**
     * Detect if a path contains video files without proper subdirectory structure.
     *
     * Used to warn users when TV show paths have flat files instead of
     * the required Series Name/Season X/episode.mkv structure.
     *
     * @param  string  $path  Path to check
     * @return array{has_flat_files: bool, file_count: int, sample_files: array<string>}
     */
    public function detectFlatStructure(string $path): array
    {
        $result = [
            'has_flat_files' => false,
            'file_count' => 0,
            'sample_files' => [],
        ];

        if (! File::exists($path) || ! File::isDirectory($path)) {
            return $result;
        }

        $subdirectories = File::directories($path);

        if (! empty($subdirectories)) {
            return $result;
        }

        $videoFiles = $this->scanDirectoryForVideoFiles($path, false);

        if (empty($videoFiles)) {
            return $result;
        }

        $result['has_flat_files'] = true;
        $result['file_count'] = count($videoFiles);
        $result['sample_files'] = array_map(
            fn (string $file): string => basename($file),
            array_slice($videoFiles, 0, 3)
        );

        return $result;
    }

    /**
     * Get warnings for series paths with flat structure.
     *
     * Checks each configured TV show path for video files sitting directly
     * in the root without the required series/season folder hierarchy.
     *
     * @return array<string> Array of warning messages
     */
    public function getSeriesPathWarnings(): array
    {
        $warnings = [];
        $tvPaths = $this->integration->getLocalMediaPathsForType('tvshows');

        foreach ($tvPaths as $pathConfig) {
            $path = $pathConfig['path'] ?? '';

            if (empty($path) || ! File::exists($path)) {
                continue;
            }

            $detection = $this->detectFlatStructure($path);

            if ($detection['has_flat_files']) {
                $samples = implode(', ', $detection['sample_files']);
                $warnings[] = "Path '{$path}' contains {$detection['file_count']} video file(s) directly (e.g., {$samples}) but no series folders. Please organize files into: Series Name/Season X/episode.mkv";
            }
        }

        return $warnings;
    }

    /**
     * Scan a directory for video files.
     *
     * @param  string  $path  Directory path to scan
     * @param  bool  $recursive  Whether to scan subdirectories
     * @return array<string> List of video file paths
     */
    protected function scanDirectoryForVideoFiles(string $path, bool $recursive = true): array
    {
        $files = [];
        $extensions = $this->integration->getVideoExtensions();

        if (! File::exists($path) || ! File::isDirectory($path)) {
            return $files;
        }

        $items = $recursive ? File::allFiles($path) : File::files($path);

        foreach ($items as $file) {
            $ext = strtolower($file->getExtension());

            if (in_array($ext, $extensions)) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Parse a movie file and extract metadata from filename.
     *
     * @param  string  $filePath  Full path to the video file
     * @return array|null Movie data array or null if parsing fails
     */
    protected function parseMovieFile(string $filePath): ?array
    {
        $filename = basename($filePath);
        $title = null;
        $year = null;
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        // Try each pattern
        foreach ($this->moviePatterns as $pattern) {
            if (preg_match($pattern, $filename, $matches)) {
                $title = $matches['title'] ?? null;
                $year = $matches['year'] ?? null;
                break;
            }
        }

        // Clean up the title
        if ($title) {
            // Replace dots and underscores with spaces
            $title = preg_replace('/[._]+/', ' ', $title);
            // Remove quality indicators
            $title = preg_replace('/\b(1080p|720p|480p|2160p|4k|hdr|bluray|webrip|webdl|dvdrip|hdtv)\b/i', '', $title);
            $title = trim($title);
        }

        if (! $title) {
            // Fallback: use filename without extension
            $title = pathinfo($filename, PATHINFO_FILENAME);
            $title = preg_replace('/[._]+/', ' ', $title);
        }

        // Generate a unique ID based on file path
        $itemId = base64_encode($filePath);

        return [
            'Id' => $itemId,
            'Name' => $title,
            'OriginalTitle' => $title,
            'ProductionYear' => $year ? (int) $year : null,
            'Path' => $filePath,
            'Container' => strtolower($extension),
            'Type' => 'Movie',
            'Genres' => ['Uncategorized'], // Would be enriched by metadata service
            'Overview' => null,
            'CommunityRating' => null,
            'RunTimeTicks' => null, // Could be extracted using ffprobe
            'People' => [],
            'MediaSources' => [
                [
                    'Container' => strtolower($extension),
                    'Path' => $filePath,
                    'Size' => File::size($filePath),
                ],
            ],
        ];
    }

    /**
     * Parse an episode file and extract metadata from filename.
     *
     * @param  string  $filePath  Full path to the video file
     * @param  string  $showName  Name of the show (from parent folder)
     * @return array|null Episode data array or null if parsing fails
     */
    protected function parseEpisodeFile(string $filePath, string $showName): ?array
    {
        $filename = basename($filePath);
        $show = $showName;
        $season = 1;
        $episode = null;
        $title = null;
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        // Check if file is in a season folder
        $parentDir = basename(dirname($filePath));
        if (preg_match('/[Ss](?:eason)?\s*(\d{1,2})/i', $parentDir, $seasonMatch)) {
            $season = (int) $seasonMatch[1];
        }

        // Try each pattern
        foreach ($this->episodePatterns as $pattern) {
            if (preg_match($pattern, $filename, $matches)) {
                if (isset($matches['show']) && ! empty($matches['show'])) {
                    $show = $matches['show'];
                }
                if (isset($matches['season'])) {
                    $season = (int) $matches['season'];
                }
                if (isset($matches['episode'])) {
                    $episode = (int) $matches['episode'];
                }
                if (isset($matches['title']) && ! empty($matches['title'])) {
                    $title = $matches['title'];
                }
                break;
            }
        }

        // If we couldn't parse the episode number, skip this file
        if ($episode === null) {
            Log::debug('LocalMediaService: Could not parse episode number', [
                'file' => $filePath,
                'filename' => $filename,
            ]);

            return null;
        }

        // Clean up show name and title
        $show = preg_replace('/[._]+/', ' ', $show);
        $show = trim($show);

        if ($title) {
            $title = preg_replace('/[._]+/', ' ', $title);
            $title = trim($title);
        } else {
            $title = "Episode {$episode}";
        }

        $itemId = base64_encode($filePath);

        return [
            'Id' => $itemId,
            'SeriesName' => $show,
            'Name' => $title,
            'IndexNumber' => $episode,
            'ParentIndexNumber' => $season,
            'Path' => $filePath,
            'Container' => strtolower($extension),
            'Type' => 'Episode',
            'Overview' => null,
            'CommunityRating' => null,
            'RunTimeTicks' => null,
            'MediaSources' => [
                [
                    'Container' => strtolower($extension),
                    'Path' => $filePath,
                    'Size' => File::size($filePath),
                ],
            ],
        ];
    }

    /**
     * Scan a series directory structure and build series data.
     *
     * @param  string  $basePath  Base path containing series folders
     * @param  array  &$seriesMap  Reference to series map to populate
     */
    protected function scanSeriesDirectory(string $basePath, array &$seriesMap): void
    {
        if (! File::exists($basePath)) {
            return;
        }

        $directories = File::directories($basePath);

        foreach ($directories as $seriesDir) {
            $seriesName = basename($seriesDir);
            $seriesId = md5($seriesDir);

            // Clean up series name
            $cleanName = preg_replace('/[._]+/', ' ', $seriesName);
            $cleanName = trim($cleanName);

            // Extract year if present in folder name
            $year = null;
            if (preg_match('/\((\d{4})\)/', $seriesName, $yearMatch)) {
                $year = $yearMatch[1];
                $cleanName = preg_replace('/\s*\(\d{4}\)\s*/', '', $cleanName);
            }

            if (! isset($seriesMap[$seriesId])) {
                $seriesMap[$seriesId] = [
                    'Id' => $seriesId,
                    'Name' => $cleanName,
                    'Path' => $seriesDir,
                    'ProductionYear' => $year,
                    'Type' => 'Series',
                    'Genres' => ['Uncategorized'],
                    'Overview' => null,
                    'CommunityRating' => null,
                ];
            }
        }

        if (empty($seriesMap)) {
            $flatFiles = $this->scanDirectoryForVideoFiles($basePath, false);

            if (! empty($flatFiles)) {
                $sampleNames = array_map(
                    fn (string $file): string => basename($file),
                    array_slice($flatFiles, 0, 3)
                );

                Log::warning('LocalMediaService: Series path contains flat video files without series folders', [
                    'path' => $basePath,
                    'file_count' => count($flatFiles),
                    'sample_files' => $sampleNames,
                    'hint' => 'Organize files into: Series Name/Season X/episode.mkv',
                ]);
            }
        }
    }

    /**
     * Scan seasons within a series folder.
     *
     * @param  string  $seriesPath  Path to the series folder
     * @return Collection<int, array>
     */
    protected function scanSeasonsInSeries(string $seriesPath): Collection
    {
        $seasons = collect();

        if (! File::exists($seriesPath)) {
            return $seasons;
        }

        $directories = File::directories($seriesPath);

        foreach ($directories as $dir) {
            $dirName = basename($dir);

            // Try to extract season number from folder name
            if (preg_match('/[Ss](?:eason)?\s*(\d{1,2})/i', $dirName, $matches)) {
                $seasonNum = (int) $matches[1];
                $seasonId = md5($dir);

                // Count episodes in this season
                $episodeFiles = $this->scanDirectoryForVideoFiles($dir, false);

                $seasons->push([
                    'Id' => $seasonId,
                    'Name' => "Season {$seasonNum}",
                    'IndexNumber' => $seasonNum,
                    'Path' => $dir,
                    'EpisodeCount' => count($episodeFiles),
                ]);
            }
        }

        // Also check for episodes directly in the series folder (no season subfolders)
        $directEpisodes = $this->scanDirectoryForVideoFiles($seriesPath, false);
        if (! empty($directEpisodes) && $seasons->isEmpty()) {
            $seasons->push([
                'Id' => md5($seriesPath.'/season1'),
                'Name' => 'Season 1',
                'IndexNumber' => 1,
                'Path' => $seriesPath,
                'EpisodeCount' => count($directEpisodes),
            ]);
        }

        return $seasons->sortBy('IndexNumber')->values();
    }
}
