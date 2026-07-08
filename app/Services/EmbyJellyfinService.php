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
 * EmbyJellyfinService - The "Brain" for Emby/Jellyfin integration
 *
 * Handles all communication with Emby/Jellyfin media servers.
 * Both platforms share the same API structure and authentication.
 */
class EmbyJellyfinService implements MediaServer
{
    protected MediaServerIntegration $integration;

    protected string $baseUrl;

    protected string $apiKey;

    /**
     * Cache of stream byte-size lookups, keyed by itemId:baseUrl. Static because
     * the same item may be looked up repeatedly across the lifetime of a long-running
     * broadcast worker tick — a per-instance cache would re-fetch on every new
     * MediaServerService::make() call.
     *
     * @var array<string, array{bytes: int, runtime_ticks: int|null, runtime_seconds: float|null}>
     */
    protected static array $streamByteSizeCache = [];

    /**
     * Create a new EmbyJellyfinService instance.
     */
    public function __construct(MediaServerIntegration $integration)
    {
        $this->integration = $integration;
        $this->baseUrl = $integration->base_url;
        $this->apiKey = $integration->api_key;
    }

    /**
     * Static factory method for convenience.
     */
    public static function make(MediaServerIntegration $integration): self
    {
        return new self($integration);
    }

    /**
     * Get a configured HTTP client for the media server.
     */
    protected function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->timeout(30)
            ->retry(2, 1000)
            ->withHeaders([
                'X-Emby-Token' => $this->apiKey,
                'Accept' => 'application/json',
            ]);
    }

    /**
     * Test connection to the media server.
     *
     * @return array{success: bool, message: string, server_name?: string, version?: string}
     */
    public function testConnection(): array
    {
        try {
            $response = $this->client()->get('/System/Info/Public');

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'success' => true,
                    'message' => 'Connection successful',
                    'server_name' => $data['ServerName'] ?? 'Unknown',
                    'version' => $data['Version'] ?? 'Unknown',
                ];
            }

            return [
                'success' => false,
                'message' => 'Server returned status: '.$response->status(),
            ];
        } catch (Exception $e) {
            Log::warning('MediaServerService: Connection test failed', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Fetch available libraries from the media server.
     * Returns only movies and TV shows libraries.
     *
     * @return Collection<int, array{id: string, name: string, type: string, item_count: int}>
     */
    public function fetchLibraries(): Collection
    {
        try {
            $response = $this->client()->get('/Library/VirtualFolders');

            if ($response->successful()) {
                $data = $response->json();

                return collect($data ?? [])
                    ->filter(function ($library) {
                        // Only include movies and tvshows libraries
                        $collectionType = $library['CollectionType'] ?? '';

                        return in_array($collectionType, ['movies', 'tvshows']);
                    })
                    ->map(function ($library) {
                        $collectionType = $library['CollectionType'] ?? 'unknown';

                        return [
                            'id' => $library['ItemId'] ?? $library['Id'] ?? '',
                            'name' => $library['Name'] ?? 'Unknown Library',
                            'type' => $collectionType,
                            'item_count' => $library['ChildCount'] ?? 0,
                            'path' => is_array($library['Locations'] ?? null)
                                ? implode(', ', $library['Locations'])
                                : ($library['Path'] ?? ''),
                        ];
                    })
                    ->values();
            }

            Log::warning('EmbyJellyfinService: Failed to fetch libraries', [
                'integration_id' => $this->integration->id,
                'status' => $response->status(),
            ]);

            return collect();
        } catch (Exception $e) {
            Log::error('EmbyJellyfinService: Error fetching libraries', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch all movies from the media server.
     * If specific libraries are selected, only fetches from those libraries.
     *
     * @return Collection<int, array>
     */
    public function fetchMovies(): Collection
    {
        try {
            $params = [
                'IncludeItemTypes' => 'Movie',
                'Recursive' => 'true',
                'Fields' => 'Genres,Path,MediaSources,Overview,CommunityRating,OfficialRating,ProductionYear,RunTimeTicks,People,OriginalTitle,PremiereDate,ProductionLocations',
                'EnableImages' => 'true',
                'ImageTypeLimit' => 1,
            ];

            // Filter by selected libraries if specified
            $selectedLibraryIds = $this->integration->getSelectedLibraryIdsForType('movies');
            if (! empty($selectedLibraryIds)) {
                // For multiple libraries, we need to fetch from each and merge
                $allMovies = collect();
                foreach ($selectedLibraryIds as $libraryId) {
                    $params['ParentId'] = $libraryId;
                    $response = $this->client()->get('/Items', $params);

                    if ($response->successful()) {
                        $data = $response->json();
                        $allMovies = $allMovies->concat(collect($data['Items'] ?? []));
                    }
                }

                return $allMovies;
            }

            // No library filter - fetch all movies
            $response = $this->client()->get('/Items', $params);

            if ($response->successful()) {
                $data = $response->json();

                return collect($data['Items'] ?? []);
            }

            Log::warning('EmbyJellyfinService: Failed to fetch movies', [
                'integration_id' => $this->integration->id,
                'status' => $response->status(),
            ]);

            return collect();
        } catch (Exception $e) {
            Log::error('EmbyJellyfinService: Error fetching movies', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch all series from the media server.
     * If specific libraries are selected, only fetches from those libraries.
     *
     * @return Collection<int, array>
     */
    public function fetchSeries(): Collection
    {
        try {
            $params = [
                'IncludeItemTypes' => 'Series',
                'Recursive' => 'true',
                'Fields' => 'Genres,Overview,CommunityRating,OfficialRating,ProductionYear',
                'EnableImages' => 'true',
                'ImageTypeLimit' => 1,
            ];

            // Filter by selected libraries if specified
            $selectedLibraryIds = $this->integration->getSelectedLibraryIdsForType('tvshows');
            if (! empty($selectedLibraryIds)) {
                // For multiple libraries, we need to fetch from each and merge
                $allSeries = collect();
                foreach ($selectedLibraryIds as $libraryId) {
                    $params['ParentId'] = $libraryId;
                    $response = $this->client()->get('/Items', $params);

                    if ($response->successful()) {
                        $data = $response->json();
                        $allSeries = $allSeries->concat(collect($data['Items'] ?? []));
                    }
                }

                return $allSeries;
            }

            // No library filter - fetch all series
            $response = $this->client()->get('/Items', $params);

            if ($response->successful()) {
                $data = $response->json();

                return collect($data['Items'] ?? []);
            }

            Log::warning('EmbyJellyfinService: Failed to fetch series', [
                'integration_id' => $this->integration->id,
                'status' => $response->status(),
            ]);

            return collect();
        } catch (Exception $e) {
            Log::error('EmbyJellyfinService: Error fetching series', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch detailed metadata for a single series (includes cast, directors, etc.).
     *
     * @param  string  $seriesId  The media server's series ID
     */
    public function fetchSeriesDetails(string $seriesId): ?array
    {
        try {
            $response = $this->client()->get("/Users/{$this->getUserId()}/Items/{$seriesId}", [
                'Fields' => 'Genres,Overview,CommunityRating,OfficialRating,ProductionYear,People,ProviderIds,ExternalUrls',
            ]);

            if ($response->successful()) {
                return $response->json();
            }

            return null;
        } catch (Exception $e) {
            Log::error('MediaServerService: Error fetching series details', [
                'integration_id' => $this->integration->id,
                'series_id' => $seriesId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get the user ID for API calls that require it.
     */
    protected function getUserId(): string
    {
        // Try to get from integration config, or use a default admin user lookup
        if (! empty($this->integration->user_id_emby)) {
            return $this->integration->user_id_emby;
        }

        // Fallback: fetch users and use the first admin
        try {
            $response = $this->client()->get('/Users');
            if ($response->successful()) {
                $users = $response->json();
                foreach ($users as $user) {
                    if ($user['Policy']['IsAdministrator'] ?? false) {
                        return $user['Id'];
                    }
                }

                // Return first user if no admin found
                return $users[0]['Id'] ?? '';
            }
        } catch (Exception $e) {
            Log::warning('MediaServerService: Could not fetch users', ['error' => $e->getMessage()]);
        }

        return '';
    }

    /**
     * Fetch all seasons for a series.
     *
     * @param  string  $seriesId  The media server's series ID
     * @return Collection<int, array>
     */
    public function fetchSeasons(string $seriesId): Collection
    {
        try {
            $response = $this->client()->get("/Shows/{$seriesId}/Seasons", [
                'Fields' => 'Overview',
            ]);

            if ($response->successful()) {
                $data = $response->json();

                return collect($data['Items'] ?? []);
            }

            return collect();
        } catch (Exception $e) {
            Log::error('MediaServerService: Error fetching seasons', [
                'integration_id' => $this->integration->id,
                'series_id' => $seriesId,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Fetch all episodes for a series (or optionally a specific season).
     *
     * @param  string  $seriesId  The media server's series ID
     * @param  string|null  $seasonId  Optional season ID to filter by
     * @return Collection<int, array>
     */
    public function fetchEpisodes(string $seriesId, ?string $seasonId = null): Collection
    {
        try {
            $params = [
                'Fields' => 'Path,MediaSources,Overview,RunTimeTicks',
            ];

            if ($seasonId) {
                $params['SeasonId'] = $seasonId;
            }

            $response = $this->client()->get("/Shows/{$seriesId}/Episodes", $params);

            if ($response->successful()) {
                $data = $response->json();

                return collect($data['Items'] ?? []);
            }

            return collect();
        } catch (Exception $e) {
            Log::error('MediaServerService: Error fetching episodes', [
                'integration_id' => $this->integration->id,
                'series_id' => $seriesId,
                'season_id' => $seasonId,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }

    /**
     * Get the proxy stream URL for an item (hides API key from clients).
     *
     * @param  string  $itemId  The media server's item ID
     * @param  string  $container  The container format (e.g., 'mp4', 'mkv', 'ts')
     */
    public function getStreamUrl(string $itemId, string $container = 'ts'): string
    {
        // Use proxy URL to hide API key from external clients
        return MediaServerProxyController::generateStreamProxyUrl(
            $this->integration->id,
            $itemId,
            $container
        );
    }

    /**
     * Get the direct stream URL for an item (internal use only - contains API key).
     *
     * @param  string  $itemId  The media server's item ID
     * @param  string  $container  The container format (e.g., 'mp4', 'mkv', 'ts')
     */
    public function getDirectStreamUrl(Request $request, string $itemId, string $container = 'ts', array $transcodeOptions = []): string
    {
        $streamUrl = "{$this->baseUrl}/Videos/{$itemId}/stream.{$container}";

        // Base parameters
        $params = [
            'api_key' => $this->apiKey,
        ];

        // static=true means "send file as-is, no transcoding". Only disable it
        // when real server-side transcode options are present. Internal helper
        // flags (e.g. skip_plex_transcode/session_id) should not affect Emby/Jellyfin.
        $effectiveTranscodeOptions = array_diff_key($transcodeOptions, [
            'skip_plex_transcode' => true,
            'session_id' => true,
        ]);

        // When an AudioStreamIndex is requested we cannot use static=true — the server
        // ignores AudioStreamIndex on a raw-file pass-through. Instead use VideoCodec=copy
        // so the server remuxes with the selected audio track while leaving the video
        // bitstream untouched (no video transcoding overhead).
        $audioStreamIndexRequested = $request->has('AudioStreamIndex');

        if (empty($effectiveTranscodeOptions) && ! $audioStreamIndexRequested) {
            $params['static'] = 'true';
        }

        // Forward relevant parameters from the incoming request
        $forwardParams = ['StartTimeTicks', 'AudioStreamIndex', 'SubtitleStreamIndex'];
        foreach ($forwardParams as $param) {
            if ($request->has($param)) {
                $params[$param] = $request->input($param);
            }
        }

        // Copy video stream when audio selection is active without a full Server transcode.
        // This avoids re-encoding the video while still letting Emby/Jellyfin pick the
        // right audio track.
        if ($audioStreamIndexRequested && empty($effectiveTranscodeOptions)) {
            $params['VideoCodec'] = 'copy';
        }

        // Include transcode options (VideoBitrate, AudioBitrate, MaxWidth, MaxHeight) if requested
        if (! empty($effectiveTranscodeOptions)) {
            if (isset($effectiveTranscodeOptions['video_bitrate'])) {
                $params['VideoBitrate'] = (string) $effectiveTranscodeOptions['video_bitrate'];
            }
            if (isset($effectiveTranscodeOptions['audio_bitrate'])) {
                $params['AudioBitrate'] = (string) $effectiveTranscodeOptions['audio_bitrate'];
            }
            if (isset($effectiveTranscodeOptions['max_width'])) {
                $params['MaxWidth'] = (int) $effectiveTranscodeOptions['max_width'];
            }
            if (isset($effectiveTranscodeOptions['max_height'])) {
                $params['MaxHeight'] = (int) $effectiveTranscodeOptions['max_height'];
            }

            // Optional codec/preset hints
            if (! empty($effectiveTranscodeOptions['video_codec'])) {
                $params['VideoCodec'] = $effectiveTranscodeOptions['video_codec'];
            }
            if (! empty($effectiveTranscodeOptions['audio_codec'])) {
                $params['AudioCodec'] = $effectiveTranscodeOptions['audio_codec'];
            }
            if (! empty($effectiveTranscodeOptions['preset'])) {
                $params['EncoderPreset'] = $effectiveTranscodeOptions['preset'];
            }
        }

        // Return the full URL with query parameters
        return $streamUrl.'?'.http_build_query($params);
    }

    /**
     * Get the proxy image URL for an item (hides API key from clients).
     *
     * @param  string  $itemId  The media server's item ID
     * @param  string  $imageType  Image type: 'Primary', 'Backdrop', 'Logo', etc.
     */
    public function getImageUrl(string $itemId, string $imageType = 'Primary'): string
    {
        // Use proxy URL to hide API key from external clients
        return MediaServerProxyController::generateImageProxyUrl(
            $this->integration->id,
            $itemId,
            $imageType
        );
    }

    /**
     * Get the direct image URL for an item (internal use only - contains API key).
     *
     * @param  string  $itemId  The media server's item ID
     * @param  string  $imageType  Image type: 'Primary', 'Backdrop', 'Logo', etc.
     */
    public function getDirectImageUrl(string $itemId, string $imageType = 'Primary'): string
    {
        return "{$this->baseUrl}/Items/{$itemId}/Images/{$imageType}?api_key={$this->apiKey}";
    }

    /**
     * Extract genres from an item, respecting the genre_handling setting.
     *
     * @param  array  $item  The item data from the API
     * @return array List of genre names
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
     * Get the container extension from media sources.
     *
     * @param  array  $item  The item data from the API
     */
    public function getContainerExtension(array $item): string
    {
        $mediaSources = $item['MediaSources'] ?? [];

        if (! empty($mediaSources)) {
            $container = $mediaSources[0]['Container'] ?? null;
            if ($container) {
                return strtolower($container);
            }
        }

        // Default fallback
        return 'ts';
    }

    /**
     * Convert runtime ticks to seconds.
     *
     * @param  int|null  $ticks  Runtime in ticks (100-nanosecond intervals)
     * @return int|null Runtime in seconds
     */
    public function ticksToSeconds(?int $ticks): ?int
    {
        if ($ticks === null) {
            return null;
        }

        // 10,000,000 ticks = 1 second
        return (int) ($ticks / 10000000);
    }

    /**
     * Trigger a library refresh/scan on the media server.
     *
     * @return array{success: bool, message: string}
     */
    public function refreshLibrary(): array
    {
        try {
            // Both Emby and Jellyfin use the same endpoint for library refresh
            $response = $this->client()->post('/Library/Refresh');

            if ($response->successful()) {
                Log::info('EmbyJellyfinService: Library refresh triggered', [
                    'integration_id' => $this->integration->id,
                    'server_name' => $this->integration->name,
                ]);

                return [
                    'success' => true,
                    'message' => 'Library refresh triggered successfully',
                ];
            }

            Log::warning('EmbyJellyfinService: Failed to trigger library refresh', [
                'integration_id' => $this->integration->id,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            return [
                'success' => false,
                'message' => 'Server returned status: '.$response->status(),
            ];
        } catch (Exception $e) {
            Log::error('EmbyJellyfinService: Error triggering library refresh', [
                'integration_id' => $this->integration->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to trigger refresh: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Fetch an item's MediaStreams, retrying briefly on an empty/incomplete result.
     *
     * Emby/Jellyfin's /Items endpoint has been observed to momentarily return a
     * successful response with no MediaStreams for a perfectly valid item —
     * apparently under concurrent API load (e.g. while the same server is also
     * serving a live transcode). Http::retry() on the client only covers
     * connection failures and 4xx/5xx responses; it does nothing for a "200 OK
     * but empty" response, which is what this guards against. This matters
     * most for the proxy auto-transition path, where subtitle/audio-language
     * resolution for the next programme runs exactly once, far ahead of when
     * it's used — a single silent miss here loses subtitles/audio-language for
     * an entire programme with nothing to catch it.
     *
     * Returns null (and logs a warning) if no usable item could be fetched.
     */
    protected function fetchItemWithMediaStreams(string $itemId, int $attempts = 3): ?array
    {
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            // Emby/Jellyfin requires /Items?Ids={id} — the /Items/{id} form 404s on some versions.
            $response = $this->client()->get('/Items', [
                'Ids' => $itemId,
                // RunTimeTicks lets getStreamByteSize() return runtime alongside the byte
                // size in a single backend round-trip. Other callers (subtitles, audio
                // stream lookup) ignore this field.
                'fields' => 'MediaStreams,RunTimeTicks',
            ]);

            if ($response->successful()) {
                $item = ($response->json('Items') ?? [])[0] ?? null;

                if ($item && ! empty($item['MediaStreams'])) {
                    return $item;
                }
            }

            if ($attempt < $attempts) {
                usleep(300_000);
            }
        }

        Log::warning('EmbyJellyfinService: /Items returned no usable MediaStreams after retries', [
            'item_id' => $itemId,
            'attempts' => $attempts,
        ]);

        return null;
    }

    /**
     * Return the MediaStreams array index of the first audio stream matching the given
     * ISO 639 language code (2- or 3-letter). Returns null if not found or on error.
     */
    public function getAudioStreamIndexForLanguage(string $itemId, string $languageCode): ?int
    {
        try {
            $item = $this->fetchItemWithMediaStreams($itemId);

            if (! $item) {
                return null;
            }

            $streams = $item['MediaStreams'] ?? [];
            $lang = strtolower($languageCode);

            foreach ($streams as $stream) {
                if (($stream['Type'] ?? '') !== 'Audio') {
                    continue;
                }

                $streamLang = strtolower($stream['Language'] ?? '');
                if ($streamLang === $lang) {
                    return (int) $stream['Index'];
                }
            }
        } catch (Exception $e) {
            Log::warning('EmbyJellyfinService: Failed to look up audio stream for language', [
                'item_id' => $itemId,
                'language' => $languageCode,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Return the first available text-based subtitle stream for the item, or null if none
     * exists. Covers both embedded and external (sidecar file) subtitle streams — this is
     * Emby/Jellyfin's own metadata, which knows about external subtitles that a raw ffprobe
     * of the video file itself can never see.
     *
     * When $seekSeconds > 0 the URL is built with a startPositionTicks path segment
     * (/Subtitles/{index}/{ticks}/Stream.{format}) so Emby/Jellyfin rebases the cue timestamps
     * to zero at that content-time. This mirrors the video stream's own StartTimeTicks seek so
     * both share a single timeline origin — the subtitle then needs no further seeking in the
     * proxy (server_seeked = true), which is what keeps subtitles locked to the video on a
     * resumed/mid-programme broadcast.
     *
     * @return array{url: string, language: ?string, server_seeked: bool}|null
     */
    public function getSubtitleUrl(string $itemId, int $seekSeconds = 0): ?array
    {
        try {
            $item = $this->fetchItemWithMediaStreams($itemId);

            if (! $item) {
                return null;
            }

            $mediaSourceId = $item['MediaSources'][0]['Id'] ?? null;

            if (! $mediaSourceId) {
                Log::warning('EmbyJellyfinService: item has MediaStreams but no MediaSources, cannot build subtitle URL', [
                    'item_id' => $itemId,
                ]);

                return null;
            }

            $streams = $item['MediaStreams'] ?? [];

            foreach ($streams as $stream) {
                if (($stream['Type'] ?? '') !== 'Subtitle') {
                    continue;
                }

                // Bitmap subtitle formats (PGS, VobSub) can't be converted to WebVTT —
                // ffmpeg's webvtt encoder only supports text-to-text conversion.
                if (! ($stream['IsTextSubtitleStream'] ?? false)) {
                    continue;
                }

                $index = $stream['Index'];
                $format = $stream['Codec'] ?? 'srt';

                // Insert the startPositionTicks path segment so Emby/Jellyfin rebases the
                // subtitle cues to zero at the seek point, exactly like StartTimeTicks does
                // for the video. Ticks are 100-nanosecond intervals.
                $seekPath = $seekSeconds > 0 ? ($seekSeconds * 10_000_000).'/' : '';

                return [
                    'url' => "{$this->baseUrl}/Videos/{$itemId}/{$mediaSourceId}/Subtitles/{$index}/{$seekPath}Stream.{$format}?api_key={$this->apiKey}",
                    'language' => $stream['Language'] ?? null,
                    'server_seeked' => $seekSeconds > 0,
                ];
            }
        } catch (Exception $e) {
            Log::warning('EmbyJellyfinService: Failed to look up subtitle URL', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Return the byte size of the item's static stream and its runtime, or null if
     * either is unavailable. Cached per item+server — the static stream URL is
     * content-addressed by item ID, so the size is stable for the lifetime of the item.
     *
     * The byte size comes from a HEAD against /Videos/{id}/stream.ts with the same
     * query string shape getDirectStreamUrl() uses for a static raw pass-through
     * (static=true, no StartTimeTicks/VideoCodec=copy). The runtime comes from
     * RunTimeTicks on the item. Together they let callers compute an HTTP Range
     * offset that aligns ffmpeg's input -ss with the server-side-seeked static URL.
     */
    public function getStreamByteSize(string $itemId): ?array
    {
        $cacheKey = $itemId.':'.$this->baseUrl;
        if (isset(self::$streamByteSizeCache[$cacheKey])) {
            return self::$streamByteSizeCache[$cacheKey];
        }

        try {
            $response = Http::baseUrl($this->baseUrl)
                ->timeout(10)
                ->withHeaders(['X-Emby-Token' => $this->apiKey])
                ->head('/Videos/'.$itemId.'/stream.ts', [
                    'static' => 'true',
                    'api_key' => $this->apiKey,
                ]);

            if (! $response->successful()) {
                return null;
            }

            $bytes = $response->header('Content-Length');
            if ($bytes === null || $bytes === '' || ! is_numeric($bytes)) {
                return null;
            }

            $bytes = (int) $bytes;

            $item = $this->fetchItemWithMediaStreams($itemId);
            $runtimeTicks = isset($item['RunTimeTicks']) ? (int) $item['RunTimeTicks'] : null;
            $runtimeSeconds = $runtimeTicks !== null ? $runtimeTicks / 10_000_000.0 : null;

            $meta = [
                'bytes' => $bytes,
                'runtime_ticks' => $runtimeTicks,
                'runtime_seconds' => $runtimeSeconds,
            ];

            self::$streamByteSizeCache[$cacheKey] = $meta;

            return $meta;
        } catch (Exception $e) {
            Log::warning('EmbyJellyfinService: Failed to read stream byte size', [
                'item_id' => $itemId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
