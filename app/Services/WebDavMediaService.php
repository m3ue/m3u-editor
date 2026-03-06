<?php

namespace App\Services;

use App\Http\Controllers\MediaServerProxyController;
use App\Interfaces\MediaServer;
use App\Models\MediaServerIntegration;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WebDavMediaService - Service for WebDAV-based media server integration
 *
 * Handles scanning and managing media files from a WebDAV server.
 * Supports movies and TV shows with metadata extraction from filenames.
 */
class WebDavMediaService implements MediaServer
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
        '/^(?<title>.+?)\s*\((?<year>\d{4})\)\s*(?:\[.*?\])?\s*\.(?<ext>\w+)$/i',
        '/^(?<title>.+?)\.(?<year>(?:19|20)\d{2})\..*\.(?<ext>\w+)$/i',
        '/^(?<title>.+?)\.(?<year>(?:19|20)\d{2})\.(?<ext>\w+)$/i',
        '/^(?<title>.+?)\s+(?<year>(?:19|20)\d{2})\s*\.(?<ext>\w+)$/i',
        '/^(?<title>.+?)\.(?<ext>\w+)$/i',
    ];

    /**
     * Regex patterns for parsing TV show episode filenames.
     */
    protected array $episodePatterns = [
        '/^(?<show>.+?)\s*[Ss](?<season>\d{1,2})[Ee](?<episode>\d{1,2})(?:\s*-\s*(?<title>.+?))?\.(?<ext>\w+)$/i',
        '/^(?<show>.+?)[.\s]+[Ss](?<season>\d{1,2})[Ee](?<episode>\d{1,2})[.\s]*(?<title>.+?)?\.(?<ext>\w+)$/i',
        '/^(?<show>.+?)\s*(?<season>\d{1,2})x(?<episode>\d{1,2})(?:\s*-?\s*(?<title>.+?))?\.(?<ext>\w+)$/i',
        '/^[Ss](?<season>\d{1,2})[Ee](?<episode>\d{1,2})(?:\s*-\s*(?<title>.+?))?\.(?<ext>\w+)$/i',
        '/^(?<episode>\d{1,2})\s*[-.]?\s*(?<title>.+?)?\.(?<ext>\w+)$/i',
    ];

    public function __construct(MediaServerIntegration $integration)
    {
        $this->integration = $integration;
    }

    public static function make(MediaServerIntegration $integration): self
    {
        return new self($integration);
    }

    /**
     * Get the base URL for the WebDAV server.
     */
    protected function getBaseUrl(): string
    {
        $protocol = $this->integration->ssl ? 'https' : 'http';
        $host = $this->integration->host;
        $port = $this->integration->port;

        $url = "{$protocol}://{$host}";

        if ($port && $port !== 80 && $port !== 443) {
            $url .= ":{$port}";
        }

        return $url;
    }

    /**
     * Get HTTP client with authentication configured.
     */
    protected function getHttpClient(): \Illuminate\Http\Client\PendingRequest
    {
        $client = Http::timeout(30)
            ->withOptions(['verify' => false]);

        $username = $this->integration->webdav_username;
        $password = $this->integration->webdav_password;

        if ($username && $password) {
            $client = $client->withBasicAuth($username, $password);
        }

        return $client;
    }

    /**
     * Test connection to the WebDAV server.
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
                    'message' => 'No media paths configured. Please add at least one WebDAV library path.',
                ];
            }

            $baseUrl = $this->getBaseUrl();
            $validPaths = 0;
            $totalFiles = 0;
            $errors = [];

            foreach ($paths as $pathConfig) {
                $path = $pathConfig['path'] ?? '';

                if (empty($path)) {
                    continue;
                }

                $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');

                try {
                    $response = $this->getHttpClient()
                        ->withHeaders([
                            'Depth' => '1',
                            'Content-Type' => 'application/xml',
                        ])
                        ->send('PROPFIND', $url, [
                            'body' => '<?xml version="1.0" encoding="utf-8"?>
                                <D:propfind xmlns:D="DAV:">
                                    <D:prop>
                                        <D:resourcetype/>
                                        <D:getcontentlength/>
                                        <D:displayname/>
                                    </D:prop>
                                </D:propfind>',
                        ]);

                    if ($response->status() === 207 || $response->successful()) {
                        $validPaths++;

                        $files = $this->scanWebDavDirectoryForVideoFiles(
                            $path,
                            $this->integration->scan_recursive
                        );
                        $totalFiles += count($files);
                    } else {
                        $errors[] = "Path not accessible: {$path} (HTTP {$response->status()})";
                    }
                } catch (Exception $e) {
                    $errors[] = "Path error: {$path} - {$e->getMessage()}";
                }
            }

            if ($validPaths === 0) {
                return [
                    'success' => false,
                    'message' => 'No valid paths found. '.implode(' ', $errors),
                ];
            }

            $message = "Found {$validPaths} valid path(s) with {$totalFiles} video file(s)";
            if (! empty($errors)) {
                $message .= '. Warnings: '.implode('; ', $errors);
            }

            return [
                'success' => true,
                'message' => $message,
                'server_name' => 'WebDAV Media',
                'version' => '1.0',
                'paths_found' => $validPaths,
                'total_files' => $totalFiles,
            ];
        } catch (Exception $e) {
            Log::error('WebDavMediaService: Connection test failed', [
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
     * Fetch available libraries - returns configured WebDAV paths as libraries.
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

            if (empty($path)) {
                continue;
            }

            $files = $this->scanWebDavDirectoryForVideoFiles(
                $path,
                $this->integration->scan_recursive
            );
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
            $libraryGenre = $pathConfig['name'] ?? basename($path);

            if (empty($path)) {
                continue;
            }

            $files = $this->scanWebDavDirectoryForVideoFiles(
                $path,
                $this->integration->scan_recursive
            );

            foreach ($files as $file) {
                $movieData = $this->parseMovieFile($file, $libraryGenre);
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
            $libraryGenre = $pathConfig['name'] ?? basename($path);

            if (empty($path)) {
                continue;
            }

            $this->scanWebDavSeriesDirectory($path, $seriesMap, $libraryGenre);
        }

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
        return null;
    }

    /**
     * Fetch seasons for a series.
     *
     * @return Collection<int, array>
     */
    public function fetchSeasons(string $seriesId): Collection
    {
        $paths = $this->integration->getLocalMediaPathsForType('tvshows');
        $seasons = collect();

        foreach ($paths as $pathConfig) {
            $basePath = $pathConfig['path'] ?? '';
            $directories = $this->listWebDavDirectory($basePath);

            foreach ($directories as $dir) {
                if ($dir['isDirectory'] && md5($dir['path']) === $seriesId) {
                    $seasons = $this->scanWebDavSeasonsInSeries($dir['path']);
                    break 2;
                }
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
            $directories = $this->listWebDavDirectory($basePath);

            foreach ($directories as $seriesDir) {
                if (! $seriesDir['isDirectory'] || md5($seriesDir['path']) !== $seriesId) {
                    continue;
                }

                $seriesName = basename($seriesDir['path']);

                if ($seasonId !== null) {
                    $seasonPath = $this->resolveSeasonPath($seriesDir['path'], $seasonId);
                    if ($seasonPath) {
                        $files = $this->scanWebDavDirectoryForVideoFiles($seasonPath, false);
                        foreach ($files as $file) {
                            $episodeData = $this->parseEpisodeFile($file, $seriesName);
                            if ($episodeData) {
                                $episodes->push($episodeData);
                            }
                        }
                    } else {
                        $files = $this->scanWebDavDirectoryForVideoFiles($seriesDir['path'], true);
                        foreach ($files as $file) {
                            $episodeData = $this->parseEpisodeFile($file, $seriesName);
                            if ($episodeData) {
                                $episodes->push($episodeData);
                            }
                        }
                    }
                } else {
                    $files = $this->scanWebDavDirectoryForVideoFiles($seriesDir['path'], true);
                    foreach ($files as $file) {
                        $episodeData = $this->parseEpisodeFile($file, $seriesName);
                        if ($episodeData) {
                            $episodes->push($episodeData);
                        }
                    }
                }
            }
        }

        return $episodes;
    }

    /**
     * Get the stream URL for an item - returns a public proxy URL.
     */
    public function getStreamUrl(string $itemId, string $container = 'ts'): string
    {
        return MediaServerProxyController::generateWebDavStreamUrl(
            $this->integration->id,
            $itemId
        );
    }

    /**
     * Get the direct stream URL.
     */
    public function getDirectStreamUrl(Request $request, string $itemId, string $container = 'ts', array $transcodeOptions = []): string
    {
        return MediaServerProxyController::generateWebDavStreamUrl(
            $this->integration->id,
            $itemId
        );
    }

    /**
     * Get image URL - for WebDAV media, we don't have images unless we fetch from TMDB.
     */
    public function getImageUrl(string $itemId, string $imageType = 'Primary'): string
    {
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
     * Convert ticks to seconds.
     */
    public function ticksToSeconds(?int $ticks): ?int
    {
        if ($ticks === null) {
            return null;
        }

        return (int) ($ticks / 10000000);
    }

    /**
     * Refresh library - triggers a rescan of WebDAV directories.
     *
     * @return array{success: bool, message: string}
     */
    public function refreshLibrary(): array
    {
        $result = $this->testConnection();

        if ($result['success']) {
            return [
                'success' => true,
                'message' => 'WebDAV media paths rescanned successfully. '.$result['message'],
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to rescan: '.$result['message'],
        ];
    }

    /**
     * List files and directories in a WebDAV directory.
     *
     * @param  string  $path  Directory path on the WebDAV server
     * @return array<array{name: string, path: string, isDirectory: bool, size: int|null}>
     */
    protected function listWebDavDirectory(string $path): array
    {
        $baseUrl = $this->getBaseUrl();
        $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');

        if (! str_ends_with($url, '/')) {
            $url .= '/';
        }

        try {
            $response = $this->getHttpClient()
                ->withHeaders([
                    'Depth' => '1',
                    'Content-Type' => 'application/xml',
                ])
                ->send('PROPFIND', $url, [
                    'body' => '<?xml version="1.0" encoding="utf-8"?>
                        <D:propfind xmlns:D="DAV:">
                            <D:prop>
                                <D:resourcetype/>
                                <D:getcontentlength/>
                                <D:displayname/>
                            </D:prop>
                        </D:propfind>',
                ]);

            if ($response->status() !== 207 && ! $response->successful()) {
                Log::warning('WebDavMediaService: Failed to list directory', [
                    'path' => $path,
                    'status' => $response->status(),
                ]);

                return [];
            }

            return $this->parseWebDavResponse($response->body(), $path);
        } catch (Exception $e) {
            Log::error('WebDavMediaService: Error listing directory', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Parse WebDAV PROPFIND response.
     *
     * @param  string  $xml  The XML response body
     * @param  string  $basePath  The base path that was queried
     * @return array<array{name: string, path: string, isDirectory: bool, size: int|null}>
     */
    protected function parseWebDavResponse(string $xml, string $basePath): array
    {
        $items = [];

        try {
            if (! class_exists(\DOMDocument::class)) {
                Log::error('WebDavMediaService: DOM extension not available for XML parsing.');

                return $items;
            }

            $previous = libxml_use_internal_errors(true);
            $doc = new \DOMDocument;

            if (! $doc->loadXML($xml)) {
                Log::error('WebDavMediaService: Invalid XML response from WebDAV server.');
                libxml_clear_errors();
                libxml_use_internal_errors($previous);

                return $items;
            }

            $xpath = new \DOMXPath($doc);
            $xpath->registerNamespace('d', 'DAV:');

            $responses = $xpath->query('//d:response');

            if ($responses === false) {
                libxml_use_internal_errors($previous);

                return $items;
            }

            foreach ($responses as $response) {
                $hrefNode = $xpath->query('./d:href', $response)->item(0);

                if (! $hrefNode) {
                    continue;
                }

                $href = $hrefNode->nodeValue ?? '';
                $decodedHref = urldecode($href);
                $hrefPath = parse_url($decodedHref, PHP_URL_PATH) ?? $decodedHref;
                $hrefPath = $hrefPath === '' ? $decodedHref : $hrefPath;

                if ($hrefPath === '') {
                    continue;
                }

                $normalizedBasePath = '/'.ltrim($basePath, '/');
                $normalizedBasePath = rtrim($normalizedBasePath, '/');

                $normalizedHrefPath = str_starts_with($hrefPath, '/')
                    ? $hrefPath
                    : $normalizedBasePath.'/'.ltrim($hrefPath, '/');

                $normalizedHrefPath = rtrim($normalizedHrefPath, '/');

                if ($normalizedHrefPath === $normalizedBasePath) {
                    continue;
                }

                $name = basename($normalizedHrefPath);

                $isDirectory = $xpath->query('.//d:resourcetype/d:collection', $response)->length > 0;

                $sizeNode = $xpath->query('.//d:getcontentlength', $response)->item(0);
                $size = $sizeNode ? (int) $sizeNode->nodeValue : null;

                $itemPath = $normalizedHrefPath;

                $items[] = [
                    'name' => $name,
                    'path' => $itemPath,
                    'href' => $href,
                    'isDirectory' => $isDirectory,
                    'size' => $size,
                ];
            }

            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        } catch (Exception $e) {
            Log::error('WebDavMediaService: Error parsing WebDAV response', [
                'error' => $e->getMessage(),
            ]);
        }

        return $items;
    }

    /**
     * Scan a WebDAV directory for video files.
     *
     * @param  string  $path  Directory path on the WebDAV server
     * @param  bool  $recursive  Whether to scan subdirectories
     * @return array<array{name: string, path: string, size: int|null}>
     */
    protected function scanWebDavDirectoryForVideoFiles(string $path, bool $recursive = true): array
    {
        $files = [];
        $extensions = $this->integration->getVideoExtensions();

        $items = $this->listWebDavDirectory($path);

        foreach ($items as $item) {
            if ($item['isDirectory']) {
                if ($recursive) {
                    $subFiles = $this->scanWebDavDirectoryForVideoFiles($item['path'], true);
                    $files = array_merge($files, $subFiles);
                }
            } else {
                $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));

                if (in_array($ext, $extensions)) {
                    $files[] = $item;
                }
            }
        }

        return $files;
    }

    /**
     * Scan a series directory on WebDAV and build series data.
     *
     * @param  string  $basePath  Base path containing series folders
     * @param  array  &$seriesMap  Reference to series map to populate
     */
    protected function scanWebDavSeriesDirectory(string $basePath, array &$seriesMap, ?string $libraryGenre = null): void
    {
        $directories = $this->listWebDavDirectory($basePath);

        foreach ($directories as $dir) {
            if (! $dir['isDirectory']) {
                continue;
            }

            $seriesName = $dir['name'];
            $seriesPath = $dir['path'];
            $seriesId = md5($seriesPath);
            $genre = $libraryGenre ? trim($libraryGenre) : '';

            $cleanName = preg_replace('/[._]+/', ' ', $seriesName);
            $cleanName = trim($cleanName);

            $year = null;
            if (preg_match('/\((\d{4})\)/', $seriesName, $yearMatch)) {
                $year = $yearMatch[1];
                $cleanName = preg_replace('/\s*\(\d{4}\)\s*/', '', $cleanName);
            }

            if (! isset($seriesMap[$seriesId])) {
                $seriesMap[$seriesId] = [
                    'Id' => $seriesId,
                    'Name' => $cleanName,
                    'Path' => $seriesPath,
                    'ProductionYear' => $year,
                    'Type' => 'Series',
                    'Genres' => $genre !== '' ? [$genre] : ['Uncategorized'],
                    'Overview' => null,
                    'CommunityRating' => null,
                ];
            }
        }
    }

    /**
     * Scan seasons within a series folder on WebDAV.
     *
     * @param  string  $seriesPath  Path to the series folder
     * @return Collection<int, array>
     */
    protected function scanWebDavSeasonsInSeries(string $seriesPath): Collection
    {
        $seasons = collect();
        $directories = $this->listWebDavDirectory($seriesPath);

        foreach ($directories as $dir) {
            if (! $dir['isDirectory']) {
                continue;
            }

            $dirName = $dir['name'];

            if (preg_match('/[Ss](?:eason)?\s*(\d{1,2})/i', $dirName, $matches)) {
                $seasonNum = (int) $matches[1];
                $seasonId = md5($dir['path']);

                $episodeFiles = $this->scanWebDavDirectoryForVideoFiles($dir['path'], false);

                $seasons->push([
                    'Id' => $seasonId,
                    'Name' => "Season {$seasonNum}",
                    'IndexNumber' => $seasonNum,
                    'Path' => $dir['path'],
                    'EpisodeCount' => count($episodeFiles),
                ]);
            }
        }

        $directEpisodes = $this->scanWebDavDirectoryForVideoFiles($seriesPath, false);
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

    /**
     * Resolve the path for a given season ID within a series directory.
     *
     * @param  string  $seriesPath  Path to the series directory
     * @param  string  $seasonId  The md5-based season ID
     * @return string|null The season directory path, or null if not found
     */
    protected function resolveSeasonPath(string $seriesPath, string $seasonId): ?string
    {
        $seasons = $this->scanWebDavSeasonsInSeries($seriesPath);

        foreach ($seasons as $season) {
            if ($season['Id'] === $seasonId) {
                return $season['Path'];
            }
        }

        return null;
    }

    /**
     * Parse a movie file and extract metadata from filename.
     *
     * @param  array{name: string, path: string, size: int|null}  $file  File information from WebDAV
     * @return array|null Movie data array or null if parsing fails
     */
    protected function parseMovieFile(array $file, ?string $libraryGenre = null): ?array
    {
        $filename = $file['name'];
        $filePath = $file['path'];
        $title = null;
        $year = null;
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        foreach ($this->moviePatterns as $pattern) {
            if (preg_match($pattern, $filename, $matches)) {
                $title = $matches['title'] ?? null;
                $year = $matches['year'] ?? null;
                break;
            }
        }

        if ($title) {
            $title = preg_replace('/[._]+/', ' ', $title);
            $title = preg_replace('/\b(1080p|720p|480p|2160p|4k|hdr|bluray|webrip|webdl|dvdrip|hdtv)\b/i', '', $title);
            $title = trim($title);
        }

        if (! $title) {
            $title = pathinfo($filename, PATHINFO_FILENAME);
            $title = preg_replace('/[._]+/', ' ', $title);
        }

        $itemId = base64_encode($filePath);
        $genre = $libraryGenre ? trim($libraryGenre) : '';

        return [
            'Id' => $itemId,
            'Name' => $title,
            'OriginalTitle' => $title,
            'ProductionYear' => $year ? (int) $year : null,
            'Path' => $filePath,
            'Container' => strtolower($extension),
            'Type' => 'Movie',
            'Genres' => $genre !== '' ? [$genre] : ['Uncategorized'],
            'Overview' => null,
            'CommunityRating' => null,
            'RunTimeTicks' => null,
            'People' => [],
            'MediaSources' => [
                [
                    'Container' => strtolower($extension),
                    'Path' => $filePath,
                    'Size' => $file['size'],
                ],
            ],
        ];
    }

    /**
     * Parse an episode file and extract metadata from filename.
     *
     * @param  array{name: string, path: string, size: int|null}  $file  File information from WebDAV
     * @param  string  $showName  Name of the show (from parent folder)
     * @return array|null Episode data array or null if parsing fails
     */
    protected function parseEpisodeFile(array $file, string $showName): ?array
    {
        $filename = $file['name'];
        $filePath = $file['path'];
        $show = $showName;
        $season = 1;
        $episode = null;
        $title = null;
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        $parentDir = basename(dirname($filePath));
        if (preg_match('/[Ss](?:eason)?\s*(\d{1,2})/i', $parentDir, $seasonMatch)) {
            $season = (int) $seasonMatch[1];
        }

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

        if ($episode === null) {
            Log::debug('WebDavMediaService: Could not parse episode number', [
                'file' => $filePath,
                'filename' => $filename,
            ]);

            return null;
        }

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
                    'Size' => $file['size'],
                ],
            ],
        ];
    }

    /**
     * Get the full URL for a file on the WebDAV server.
     *
     * @param  string  $filePath  The path to the file on the WebDAV server
     * @return string The full HTTP URL to the file
     */
    public function getFileUrl(string $filePath): string
    {
        $baseUrl = $this->getBaseUrl();

        return rtrim($baseUrl, '/').'/'.ltrim($filePath, '/');
    }

    /**
     * Get authentication credentials for streaming.
     *
     * @return array{username: string|null, password: string|null}
     */
    public function getCredentials(): array
    {
        return [
            'username' => $this->integration->webdav_username,
            'password' => $this->integration->webdav_password,
        ];
    }
}
