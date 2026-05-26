<?php

namespace App\Services;

use App\Http\Controllers\MediaServerProxyController;
use App\Interfaces\MediaServer;
use App\Models\MediaServerIntegration;
use Exception;
use Illuminate\Http\Client\PendingRequest;
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
     * Cache populated by fetchSeries() when torrent parser is active.
     * Keyed by series ID — allows fetchSeasons() and fetchEpisodes() to find
     * episode-container grouped series that have no single directory path.
     *
     * @var array<string, array>
     */
    protected array $torrentSeriesCache = [];

    /**
     * Optional callback invoked during the scan/fetch phases with the running item count.
     * Used by the sync job to report incremental progress while WebDAV directories are
     * being scanned (before DB writes begin).
     */
    protected ?\Closure $scanProgressCallback = null;

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
     * Register a callback invoked during directory scanning with the running found-item count.
     * The job uses this to show incremental progress while WebDAV is being scanned.
     */
    public function setScanProgressCallback(\Closure $fn): void
    {
        $this->scanProgressCallback = $fn;
    }

    /**
     * Get the base URL for the WebDAV server, including any configured base path.
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

        $basePath = trim($this->integration->webdav_base_path ?? '', '/');
        if ($basePath !== '') {
            $url .= "/{$basePath}";
        }

        return $url;
    }

    /**
     * Get HTTP client with authentication configured.
     */
    protected function getHttpClient(): PendingRequest
    {
        $verify = $this->integration->ssl && ! $this->integration->skip_ssl_verify;

        $client = Http::timeout(30)
            ->withOptions(['verify' => $verify]);

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
     * First verifies root connectivity and auth, then validates any configured library paths.
     *
     * @return array{success: bool, message: string, paths_found?: int, total_files?: int}
     */
    public function testConnection(): array
    {
        try {
            $baseUrl = $this->getBaseUrl();

            // Step 1: test root connectivity regardless of configured paths
            $rootResult = $this->propfindUrl($baseUrl.'/');
            if (! $rootResult['success']) {
                return [
                    'success' => false,
                    'message' => "Cannot reach WebDAV server at {$baseUrl}: {$rootResult['error']}",
                ];
            }

            // Step 2: validate configured library paths
            $paths = $this->integration->local_media_paths ?? [];
            $configuredPaths = array_filter($paths, fn ($p) => ! empty($p['path'] ?? ''));

            if (empty($configuredPaths)) {
                return [
                    'success' => true,
                    'message' => "Connected to WebDAV server at {$baseUrl}.",
                    'server_name' => 'WebDAV Media',
                    'version' => '1.0',
                    'paths_found' => 0,
                    'total_files' => 0,
                ];
            }

            $validPaths = 0;
            $errors = [];

            foreach ($configuredPaths as $pathConfig) {
                $path = $pathConfig['path'];
                $url = rtrim($baseUrl, '/').'/'.ltrim($path, '/');

                $result = $this->propfindUrl($url);

                if ($result['success']) {
                    $validPaths++;
                } else {
                    $errors[] = "Path '{$path}': {$result['error']}";
                }
            }

            if ($validPaths === 0) {
                return [
                    'success' => false,
                    'message' => 'Server reachable but no library paths are accessible. '.implode('; ', $errors),
                ];
            }

            $message = "Connected — {$validPaths} path(s) accessible";
            if (! empty($errors)) {
                $message .= '. Warnings: '.implode('; ', $errors);
            }

            return [
                'success' => true,
                'message' => $message,
                'server_name' => 'WebDAV Media',
                'version' => '1.0',
                'paths_found' => $validPaths,
                'total_files' => 0,
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
     * Issue a PROPFIND request to a URL and return success/error.
     *
     * @return array{success: bool, error?: string}
     */
    protected function propfindUrl(string $url): array
    {
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
                return ['success' => true];
            }

            $label = match ($response->status()) {
                401 => 'HTTP 401 Unauthorized — check username/password',
                403 => 'HTTP 403 Forbidden — credentials valid but access denied',
                404 => 'HTTP 404 Not Found — path does not exist on the server',
                405 => 'HTTP 405 Method Not Allowed — server may not support WebDAV',
                default => "HTTP {$response->status()}",
            };

            return ['success' => false, 'error' => $label];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
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

            // Shallow Depth:1 listing — fast single request, avoids recursive scan on remote servers.
            // Full file count happens during the actual SyncMediaServer job.
            $itemCount = count($this->listWebDavDirectory($path));

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
        $parser = $this->integration->use_torrent_parser ? new TorrentTitleParser : null;

        foreach ($paths as $pathConfig) {
            $path = $pathConfig['path'] ?? '';
            $libraryGenre = $pathConfig['name'] ?? basename($path);
            $isBothPath = ($pathConfig['type'] ?? '') === 'both';

            if (empty($path)) {
                continue;
            }

            $files = $this->scanWebDavDirectoryForVideoFiles(
                $path,
                $this->integration->scan_recursive
            );

            $normalizedScanPath = rtrim($path, '/');

            foreach ($files as $file) {
                // Skip files that live inside a subdirectory which looks like a season/series pack.
                // This prevents junk files (promo .mkv, sample, etc.) bundled inside a series torrent
                // from appearing as standalone movies.
                $fileDir = rtrim(dirname($file['path']), '/');
                if ($fileDir !== $normalizedScanPath) {
                    $parentName = basename($fileDir);
                    if ($parser) {
                        $parentParsed = $parser->parse($parentName);
                        if ($parentParsed['is_episode'] || $parentParsed['is_pack']) {
                            continue;
                        }
                    } elseif (preg_match('/\b[Ss]\d{1,2}\b/u', $parentName)) {
                        continue;
                    }
                }

                $isEpisode = $parser
                    ? $parser->parse($file['name'])['is_episode']
                    : ($isBothPath && $this->looksLikeEpisode($file['name']));

                // When torrent parser is on, skip episodes everywhere (not just 'both' paths)
                if ($isEpisode) {
                    continue;
                }

                $movieData = $parser
                    ? $this->parseMovieFileTorrent($file, $parser->parse($file['name']), $libraryGenre)
                    : $this->parseMovieFile($file, $libraryGenre);

                if ($movieData) {
                    $movies->push($movieData);

                    if ($this->scanProgressCallback) {
                        ($this->scanProgressCallback)($movies->count());
                    }
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

            if ($this->integration->use_torrent_parser) {
                $this->parseTorrentSeriesDirectory($path, $seriesMap, $libraryGenre);
            } else {
                $this->scanWebDavSeriesDirectory($path, $seriesMap, $libraryGenre);
            }
        }

        // Populate instance cache so fetchSeasons / fetchEpisodes can resolve grouped series
        $this->torrentSeriesCache = $seriesMap;

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
        // Torrent-grouped series: seasons are derived from the episode containers collected
        // during fetchSeries() rather than from a single directory on the server.
        if (isset($this->torrentSeriesCache[$seriesId]['_torrent_episode_dirs'])) {
            return $this->buildSeasonsFromEpisodeDirs(
                $this->torrentSeriesCache[$seriesId]['_torrent_episode_dirs']
            );
        }

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
        // Torrent-grouped series: episodes come from per-episode container directories.
        if (isset($this->torrentSeriesCache[$seriesId]['_torrent_episode_dirs'])) {
            return $this->buildEpisodesFromTorrentDirs(
                $this->torrentSeriesCache[$seriesId],
                $seasonId
            );
        }

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
                $torrentParser = $this->integration->use_torrent_parser ? new TorrentTitleParser : null;

                $parseFiles = function (array $files) use (&$episodes, $seriesName, $torrentParser): void {
                    foreach ($files as $file) {
                        // When the torrent parser is active, skip files that clearly aren't episodes
                        // (junk promo/sample files included in series torrents) without emitting a log.
                        if ($torrentParser && ! $torrentParser->parse($file['name'])['is_episode']) {
                            continue;
                        }

                        $episodeData = $this->parseEpisodeFile($file, $seriesName);
                        if ($episodeData) {
                            $episodes->push($episodeData);
                        }
                    }
                };

                if ($seasonId !== null) {
                    $seasonPath = $this->resolveSeasonPath($seriesDir['path'], $seasonId);
                    if ($seasonPath) {
                        $parseFiles($this->scanWebDavDirectoryForVideoFiles($seasonPath, false));
                    } else {
                        $parseFiles($this->scanWebDavDirectoryForVideoFiles($seriesDir['path'], true));
                    }
                } else {
                    $parseFiles($this->scanWebDavDirectoryForVideoFiles($seriesDir['path'], true));
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
    /**
     * Build a properly percent-encoded URL from the base URL and a decoded path.
     * Each path segment is encoded individually so slashes are preserved as separators.
     */
    protected function pathToUrl(string $path): string
    {
        $segments = explode('/', ltrim($path, '/'));
        $encoded = implode('/', array_map('rawurlencode', $segments));

        return rtrim($this->getBaseUrl(), '/').'/'.$encoded;
    }

    protected function listWebDavDirectory(string $path): array
    {
        $url = $this->pathToUrl($path);

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

                $name = $this->sanitizeUtf8(basename($normalizedHrefPath));

                $isDirectory = $xpath->query('.//d:resourcetype/d:collection', $response)->length > 0;

                $sizeNode = $xpath->query('.//d:getcontentlength', $response)->item(0);
                $size = $sizeNode ? (int) $sizeNode->nodeValue : null;

                $itemPath = $this->sanitizeUtf8($normalizedHrefPath);

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

    // -------------------------------------------------------------------------
    // Torrent-parser mode helpers
    // -------------------------------------------------------------------------

    /**
     * Scan a WebDAV directory for series when torrent-style naming is enabled.
     *
     * Directories whose names contain SxxExx markers are treated as single-episode
     * container folders (common for per-torrent downloads). These are grouped by
     * show title into virtual series so that all episodes of the same show appear
     * under one entry rather than each getting its own series.
     *
     * @param  array<string, array>  $seriesMap
     */
    protected function parseTorrentSeriesDirectory(string $basePath, array &$seriesMap, ?string $libraryGenre = null): void
    {
        $parser = new TorrentTitleParser;
        $directories = $this->listWebDavDirectory($basePath);

        // Accumulator for episode-container dirs, keyed by normalised show title
        $episodeContainers = [];

        foreach ($directories as $dir) {
            if (! $dir['isDirectory']) {
                continue;
            }

            // TorrentTitleParser already strips site watermarks (www.SiteName.org, [SOMESITE.ORG], …)
            // from the directory name before pattern matching, so we parse the raw directory name
            // directly. Do NOT replace it with the NFO "Title:" field here — episode-title strings
            // like "This Is Art, This Is Spirituality" would fail episode detection.
            $parsed = $parser->parse($dir['name']);

            if ($parsed['is_episode']) {
                // Single-episode container: group under the show name
                $showTitle = $parsed['title'] ?: $dir['name'];
                $key = strtolower(trim($showTitle));

                if (! isset($episodeContainers[$key])) {
                    $episodeContainers[$key] = [
                        'title' => $showTitle,
                        'dirs' => [],
                    ];
                }

                $episodeContainers[$key]['dirs'][] = [
                    'dir' => $dir,
                    'season' => $parsed['season'] ?? 1,
                    'episode' => $parsed['episode'],
                ];
            } else {
                // Regular series directory or multi-season pack — use directory path as ID.
                // If no season/pack markers are present, do a shallow check to avoid treating
                // a single-movie directory (e.g. Remarkably Bright Creatures/) as a series.
                if (! $parsed['is_pack'] && $parsed['season'] === null) {
                    if ($this->looksLikeMovieDirectory($dir['path'], $parser)) {
                        continue;
                    }
                }

                $seriesTitle = $parsed['title'] ?: $dir['name'];
                $seriesId = md5($dir['path']);

                if (! isset($seriesMap[$seriesId])) {
                    $genre = $libraryGenre ? trim($libraryGenre) : '';
                    $seriesMap[$seriesId] = [
                        'Id' => $seriesId,
                        'Name' => $seriesTitle,
                        'Path' => $dir['path'],
                        'ProductionYear' => $parsed['year'],
                        'Type' => 'Series',
                        'Genres' => $genre !== '' ? [$genre] : ['Uncategorized'],
                        'Overview' => null,
                        'CommunityRating' => null,
                    ];
                }
            }
        }

        // Merge grouped episode-container series into seriesMap
        foreach ($episodeContainers as $key => $data) {
            $seriesId = md5('torrent-show:'.$key);
            $genre = $libraryGenre ? trim($libraryGenre) : '';

            $seriesMap[$seriesId] = [
                'Id' => $seriesId,
                'Name' => $data['title'],
                'Path' => $basePath,
                'ProductionYear' => null,
                'Type' => 'Series',
                'Genres' => $genre !== '' ? [$genre] : ['Uncategorized'],
                'Overview' => null,
                'CommunityRating' => null,
                '_torrent_episode_dirs' => $data['dirs'],
            ];

            if ($this->scanProgressCallback) {
                ($this->scanProgressCallback)(count($seriesMap));
            }
        }
    }

    /**
     * Build a seasons collection from episode-container directory metadata.
     *
     * @param  array<int, array{dir: array, season: int, episode: int|null}>  $episodeDirs
     * @return Collection<int, array>
     */
    protected function buildSeasonsFromEpisodeDirs(array $episodeDirs): Collection
    {
        $seasons = [];

        foreach ($episodeDirs as $entry) {
            $season = $entry['season'];
            if (! isset($seasons[$season])) {
                $seasons[$season] = [
                    'Id' => md5("torrent-season:{$season}"),
                    'Name' => "Season {$season}",
                    'IndexNumber' => $season,
                    'Path' => '',
                    'EpisodeCount' => 0,
                ];
            }
            $seasons[$season]['EpisodeCount']++;
        }

        return collect(array_values($seasons))->sortBy('IndexNumber')->values();
    }

    /**
     * Build an episodes collection by scanning the episode-container directories
     * stored in a torrent-grouped series entry.
     *
     * @return Collection<int, array>
     */
    protected function buildEpisodesFromTorrentDirs(array $seriesData, ?string $seasonId): Collection
    {
        $episodes = collect();
        $seriesName = $seriesData['Name'];
        $parser = new TorrentTitleParser;
        $videoExtensions = $this->integration->getVideoExtensions();

        foreach ($seriesData['_torrent_episode_dirs'] as $entry) {
            $season = $entry['season'];
            $targetSeasonId = md5("torrent-season:{$season}");

            if ($seasonId !== null && $targetSeasonId !== $seasonId) {
                continue;
            }

            // List the episode container directory once to collect both video files and the NFO.
            // The NFO "Title:" field provides the human-readable episode name.
            $allItems = $this->listWebDavDirectory($entry['dir']['path']);
            $files = [];
            $nfoTitle = null;

            foreach ($allItems as $item) {
                if ($item['isDirectory']) {
                    continue;
                }

                $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));

                if (in_array($ext, $videoExtensions, true)) {
                    $files[] = $item;
                } elseif ($ext === 'nfo' && $nfoTitle === null) {
                    $nfoTitle = $this->fetchNfoDisplayTitle($item['path']);
                }
            }

            foreach ($files as $file) {
                // Try torrent parser first, fall back to regex parseEpisodeFile
                $fileParsed = $parser->parse($file['name']);

                if ($fileParsed['is_episode']) {
                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $itemId = base64_encode($file['path']);
                    $episodes->push([
                        'Id' => $itemId,
                        'SeriesName' => $seriesName,
                        'Name' => $nfoTitle ?? "Episode {$fileParsed['episode']}",
                        'IndexNumber' => $fileParsed['episode'],
                        'ParentIndexNumber' => $fileParsed['season'] ?? $season,
                        'Path' => $file['path'],
                        'Container' => $ext,
                        'Type' => 'Episode',
                        'Overview' => null,
                        'CommunityRating' => null,
                        'RunTimeTicks' => null,
                        'MediaSources' => [[
                            'Container' => $ext,
                            'Path' => $file['path'],
                            'Size' => $file['size'],
                        ]],
                    ]);
                } else {
                    // File parser couldn't confirm it's an episode — use dir-level info
                    $episodeData = $this->parseEpisodeFile($file, $seriesName);
                    if ($episodeData) {
                        if (! $episodeData['ParentIndexNumber']) {
                            $episodeData['ParentIndexNumber'] = $season;
                        }
                        if (! $episodeData['IndexNumber']) {
                            $episodeData['IndexNumber'] = $entry['episode'];
                        }
                        $episodes->push($episodeData);
                    }
                }
            }
        }

        return $episodes;
    }

    /**
     * Parse a movie file using pre-computed torrent parser output.
     */
    protected function parseMovieFileTorrent(array $file, array $parsed, ?string $libraryGenre = null): ?array
    {
        $title = $parsed['title'] ?: pathinfo($file['name'], PATHINFO_FILENAME);
        $year = $parsed['year'];
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $itemId = base64_encode($file['path']);
        $genre = $libraryGenre ? trim($libraryGenre) : '';

        return [
            'Id' => $itemId,
            'Name' => $title,
            'OriginalTitle' => $title,
            'ProductionYear' => $year,
            'Path' => $file['path'],
            'Container' => $extension,
            'Type' => 'Movie',
            'Genres' => $genre !== '' ? [$genre] : ['Uncategorized'],
            'Overview' => null,
            'CommunityRating' => null,
            'RunTimeTicks' => null,
            'People' => [],
            'MediaSources' => [[
                'Container' => $extension,
                'Path' => $file['path'],
                'Size' => $file['size'],
            ]],
        ];
    }

    /**
     * Fetch the human-readable "Title:" field from an NFO file at a known path.
     * Used inside episode container directories to get the proper episode title
     * (e.g. "This Is Art, This Is Spirituality" rather than "Episode 3").
     */
    protected function fetchNfoDisplayTitle(string $nfoPath): ?string
    {
        try {
            $response = $this->getHttpClient()->get($this->pathToUrl($nfoPath));

            if (! $response->successful()) {
                return null;
            }

            $content = $response->body();

            // Parse "Title           : Some Title..." format (NFO info files)
            if (preg_match('/^Title\s*:\s*(.+)$/mi', $content, $m)) {
                $title = trim($m[1]);

                return $title !== '' ? $this->sanitizeUtf8($title) : null;
            }
        } catch (Exception $e) {
            // NFO fetch is best-effort; continue without it
        }

        return null;
    }

    /**
     * Return true if a directory appears to contain a single movie file rather than
     * a TV series (no season subdirectories, no episode-pattern video files).
     * Uses a single shallow PROPFIND — called only when the directory name lacks
     * any season/episode markers.
     */
    protected function looksLikeMovieDirectory(string $dirPath, TorrentTitleParser $parser): bool
    {
        $extensions = $this->integration->getVideoExtensions();
        $items = $this->listWebDavDirectory($dirPath);

        $videoFiles = [];
        $hasSubDirs = false;

        foreach ($items as $item) {
            if ($item['isDirectory']) {
                $hasSubDirs = true;
                break;
            }

            $ext = strtolower(pathinfo($item['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $extensions, true)) {
                $videoFiles[] = $item;
            }
        }

        if ($hasSubDirs) {
            return false;
        }

        // If there are video files but none match episode patterns, it's a movie directory
        if (! empty($videoFiles)) {
            foreach ($videoFiles as $vf) {
                if ($parser->parse($vf['name'])['is_episode']) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Strip invalid UTF-8 bytes from a string so it can be safely stored in the database.
     * TorBox (and some other WebDAV servers) return filenames that mix Latin-2 bytes
     * into otherwise UTF-8 content, producing sequences like 0xc5 0x20 that PostgreSQL
     * and json_encode both reject.
     */
    protected function sanitizeUtf8(string $str): string
    {
        return mb_scrub($str, 'UTF-8');
    }

    /**
     * Check whether a filename matches known TV episode patterns.
     */
    protected function looksLikeEpisode(string $filename): bool
    {
        foreach ($this->episodePatterns as $pattern) {
            if (preg_match($pattern, $filename)) {
                return true;
            }
        }

        return false;
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
        return $this->pathToUrl($filePath);
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
