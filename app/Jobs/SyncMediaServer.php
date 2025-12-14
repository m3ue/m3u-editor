<?php

namespace App\Jobs;

use App\Enums\PlaylistSourceType;
use App\Enums\Status;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Services\MediaServerService;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SyncMediaServer Job - The "Engine" for Emby/Jellyfin sync
 *
 * Fetches content from a media server and syncs it into the M3U Editor's
 * standard tables (playlists, groups, channels, categories, series, episodes).
 */
class SyncMediaServer implements ShouldQueue
{
    use Queueable;

    /**
     * Number of times to retry on failure.
     */
    public int $tries = 1;

    /**
     * Timeout in seconds (30 minutes for large libraries).
     */
    public int $timeout = 1800;

    /**
     * Sync statistics.
     */
    protected array $stats = [
        'movies_synced' => 0,
        'series_synced' => 0,
        'episodes_synced' => 0,
        'groups_created' => 0,
        'categories_created' => 0,
        'errors' => [],
    ];

    /**
     * Batch number for this sync operation.
     */
    protected string $batchNo;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $integrationId,
    ) {
        $this->batchNo = Str::orderedUuid()->toString();
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $integration = MediaServerIntegration::find($this->integrationId);

        if (!$integration) {
            Log::error('SyncMediaServer: Integration not found', ['id' => $this->integrationId]);
            return;
        }

        if (!$integration->enabled) {
            Log::info('SyncMediaServer: Integration is disabled', ['id' => $this->integrationId]);
            return;
        }

        Log::info('SyncMediaServer: Starting sync', [
            'integration_id' => $integration->id,
            'name' => $integration->name,
            'type' => $integration->type,
        ]);

        try {
            // Ensure playlist exists for this integration
            $playlist = $this->ensurePlaylist($integration);

            // Update playlist status
            $playlist->update([
                'status' => Status::Processing,
                'processing' => ['syncing' => true],
            ]);

            // Create the service
            $service = MediaServerService::make($integration);

            // Test connection first
            $connectionTest = $service->testConnection();
            if (!$connectionTest['success']) {
                throw new Exception('Connection failed: ' . $connectionTest['message']);
            }

            // Sync movies (as VOD channels)
            if ($integration->import_movies) {
                $this->syncMovies($integration, $playlist, $service);
            }

            // Sync series and episodes
            if ($integration->import_series) {
                $this->syncSeries($integration, $playlist, $service);
            }

            // Update integration with sync stats
            $integration->update([
                'last_synced_at' => now(),
                'sync_stats' => $this->stats,
            ]);

            // Update playlist status
            $playlist->update([
                'status' => Status::Completed,
                'processing' => [],
                'synced' => now(),
            ]);

            // Send success notification
            Notification::make()
                ->success()
                ->title('Media Server Sync Complete')
                ->body("Synced {$this->stats['movies_synced']} movies and {$this->stats['series_synced']} series from {$integration->name}")
                ->broadcast($integration->user)
                ->sendToDatabase($integration->user);

            Log::info('SyncMediaServer: Sync completed', [
                'integration_id' => $integration->id,
                'stats' => $this->stats,
            ]);

        } catch (Exception $e) {
            $this->stats['errors'][] = $e->getMessage();

            // Update integration with error
            $integration->update([
                'sync_stats' => $this->stats,
            ]);

            // Update playlist status if it exists
            if (isset($playlist)) {
                $playlist->update([
                    'status' => Status::Failed,
                    'processing' => [],
                    'errors' => $e->getMessage(),
                ]);
            }

            // Send error notification
            Notification::make()
                ->danger()
                ->title('Media Server Sync Failed')
                ->body("Failed to sync {$integration->name}: {$e->getMessage()}")
                ->broadcast($integration->user)
                ->sendToDatabase($integration->user);

            Log::error('SyncMediaServer: Sync failed', [
                'integration_id' => $integration->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Ensure a playlist exists for this integration.
     */
    protected function ensurePlaylist(MediaServerIntegration $integration): Playlist
    {
        if ($integration->playlist_id && $integration->playlist) {
            return $integration->playlist;
        }

        // Determine source type based on integration type
        $sourceType = $integration->type === 'emby'
            ? PlaylistSourceType::Emby
            : PlaylistSourceType::Jellyfin;

        // Create a new playlist for this integration
        $playlist = Playlist::create([
            'name' => $integration->name,
            'url' => $integration->base_url, // Store the server URL for reference
            'user_id' => $integration->user_id,
            'source_type' => $sourceType,
            'status' => Status::Pending,
            'auto_sync' => false, // Sync is managed by the integration, not the playlist
        ]);

        // Link the playlist to the integration
        $integration->update(['playlist_id' => $playlist->id]);

        return $playlist;
    }

    /**
     * Sync movies from the media server as VOD channels.
     */
    protected function syncMovies(
        MediaServerIntegration $integration,
        Playlist $playlist,
        MediaServerService $service
    ): void {
        $movies = $service->fetchMovies();

        Log::info('SyncMediaServer: Fetched movies', [
            'integration_id' => $integration->id,
            'count' => $movies->count(),
        ]);

        foreach ($movies as $movie) {
            try {
                $this->syncMovie($integration, $playlist, $service, $movie);
                $this->stats['movies_synced']++;
            } catch (Exception $e) {
                $this->stats['errors'][] = "Movie '{$movie['Name']}': {$e->getMessage()}";
                Log::warning('SyncMediaServer: Failed to sync movie', [
                    'movie' => $movie['Name'] ?? 'Unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Sync a single movie as a VOD channel.
     */
    protected function syncMovie(
        MediaServerIntegration $integration,
        Playlist $playlist,
        MediaServerService $service,
        array $movie
    ): void {
        $itemId = $movie['Id'];
        $genres = $service->extractGenres($movie);
        $container = $service->getContainerExtension($movie);

        // Ensure group exists for the first genre (or all if genre_handling='all')
        $group = $this->ensureGroup($playlist, $genres[0]);

        // Build the direct stream URL
        $streamUrl = $service->getStreamUrl($itemId, $container);
        $imageUrl = $service->getImageUrl($itemId, 'Primary');
        $backdropUrl = $service->getImageUrl($itemId, 'Backdrop');

        // Extract runtime in minutes and convert to formatted duration
        $runtimeTicks = $movie['RunTimeTicks'] ?? 0;
        $runtimeSeconds = $service->ticksToSeconds($runtimeTicks);
        $runtimeMinutes = (int) ($runtimeSeconds / 60);
        $duration = gmdate('H:i:s', $runtimeSeconds);

        // Extract director(s) from People array
        $directors = array_column(
            array_filter($movie['People'] ?? [], fn($p) => ($p['Type'] ?? '') === 'Director'),
            'Name'
        );

        // Extract actors from People array (limit to 10)
        $actors = array_slice(array_column(
            array_filter($movie['People'] ?? [], fn($p) => ($p['Type'] ?? '') === 'Actor'),
            'Name'
        ), 0, 10);

        // Handle ProductionLocations - might be array or string
        $locations = $movie['ProductionLocations'] ?? [];
        $country = is_array($locations) ? implode(', ', $locations) : (string) $locations;

        // Build info structure compatible with Xtream API output
        $info = [
            'media_server_id' => $itemId,
            'media_server_type' => $integration->type,
            'name' => $movie['Name'],
            'o_name' => $movie['OriginalTitle'] ?? $movie['Name'],
            'cover_big' => $imageUrl,
            'movie_image' => $imageUrl,
            'release_date' => $movie['PremiereDate'] ?? null,
            'plot' => $movie['Overview'] ?? '',
            'description' => $movie['Overview'] ?? '',
            'director' => implode(', ', $directors),
            'actors' => implode(', ', $actors),
            'cast' => implode(', ', $actors),
            'genre' => implode(', ', $genres),
            'duration_secs' => $runtimeSeconds,
            'duration' => $duration,
            'episode_run_time' => $runtimeMinutes,
            'backdrop_path' => $backdropUrl ? [$backdropUrl] : [],
            'youtube_trailer' => null,
            'country' => $country,
        ];

        // Create or update the channel
        Channel::updateOrCreate(
            [
                'playlist_id' => $playlist->id,
                'source_id' => "media-server-{$integration->id}-{$itemId}",
            ],
            [
                'name' => $movie['Name'],
                'title' => $movie['Name'],
                'url' => $streamUrl,
                'logo' => $imageUrl,
                'logo_internal' => $imageUrl,
                'group' => $group->name,
                'group_internal' => $group->name,
                'group_id' => $group->id,
                'user_id' => $playlist->user_id,
                'enabled' => true,
                'is_vod' => true,
                'container_extension' => $container,
                'import_batch_no' => $this->batchNo,
                'year' => $movie['ProductionYear'] ?? null,
                'rating' => $movie['CommunityRating'] ?? null,
                'info' => $info,
                'last_metadata_fetch' => now(), // Mark metadata as fetched so Xtream API doesn't try to fetch again
            ]
        );
    }

    /**
     * Ensure a group exists for the given genre.
     */
    protected function ensureGroup(Playlist $playlist, string $genreName): Group
    {
        $group = Group::where('playlist_id', $playlist->id)
            ->where('name', $genreName)
            ->first();

        if (!$group) {
            $group = Group::create([
                'name' => $genreName,
                'name_internal' => $genreName,
                'user_id' => $playlist->user_id,
                'playlist_id' => $playlist->id,
                'type' => 'vod',
            ]);
            $this->stats['groups_created']++;
        }

        return $group;
    }

    /**
     * Sync series from the media server.
     */
    protected function syncSeries(
        MediaServerIntegration $integration,
        Playlist $playlist,
        MediaServerService $service
    ): void {
        $seriesList = $service->fetchSeries();

        Log::info('SyncMediaServer: Fetched series', [
            'integration_id' => $integration->id,
            'count' => $seriesList->count(),
        ]);

        foreach ($seriesList as $seriesData) {
            try {
                $this->syncOneSeries($integration, $playlist, $service, $seriesData);
                $this->stats['series_synced']++;
            } catch (Exception $e) {
                $this->stats['errors'][] = "Series '{$seriesData['Name']}': {$e->getMessage()}";
                Log::warning('SyncMediaServer: Failed to sync series', [
                    'series' => $seriesData['Name'] ?? 'Unknown',
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Sync a single series with its seasons and episodes.
     */
    protected function syncOneSeries(
        MediaServerIntegration $integration,
        Playlist $playlist,
        MediaServerService $service,
        array $seriesData
    ): void {
        $seriesId = $seriesData['Id'];
        $genres = $service->extractGenres($seriesData);

        // Ensure category exists for the first genre
        $category = $this->ensureCategory($playlist, $genres[0]);

        // Create or update the series
        $series = Series::updateOrCreate(
            [
                'playlist_id' => $playlist->id,
                'source_series_id' => crc32("media-server-{$integration->id}-{$seriesId}"),
            ],
            [
                'name' => $seriesData['Name'],
                'user_id' => $playlist->user_id,
                'category_id' => $category->id,
                'source_category_id' => $category->source_category_id ?? $category->id,
                'import_batch_no' => $this->batchNo,
                'enabled' => true,
                'cover' => $service->getImageUrl($seriesId, 'Primary'),
                'plot' => $seriesData['Overview'] ?? null,
                'genre' => implode(', ', $genres),
                'release_date' => $seriesData['ProductionYear'] ?? null,
                'rating' => $seriesData['CommunityRating'] ?? null,
                'last_metadata_fetch' => now(), // Mark metadata as fetched so Xtream API doesn't try to fetch again
                'metadata' => [
                    'media_server_id' => $seriesId,
                    'media_server_type' => $integration->type,
                ],
            ]
        );

        // Fetch and sync seasons
        $seasons = $service->fetchSeasons($seriesId);
        foreach ($seasons as $seasonData) {
            $this->syncSeason($integration, $playlist, $service, $series, $seasonData);
        }
    }

    /**
     * Sync a season and its episodes.
     */
    protected function syncSeason(
        MediaServerIntegration $integration,
        Playlist $playlist,
        MediaServerService $service,
        Series $series,
        array $seasonData
    ): void {
        $seasonId = $seasonData['Id'];
        $seasonNumber = $seasonData['IndexNumber'] ?? 1;

        // Create or update the season
        $season = Season::updateOrCreate(
            [
                'series_id' => $series->id,
                'season_number' => $seasonNumber,
            ],
            [
                'name' => $seasonData['Name'] ?? "Season {$seasonNumber}",
                'user_id' => $playlist->user_id,
                'playlist_id' => $playlist->id,
                'cover' => $service->getImageUrl($seasonId, 'Primary'),
                'import_batch_no' => $this->batchNo,
                'metadata' => [
                    'media_server_id' => $seasonId,
                    'media_server_type' => $integration->type,
                    'overview' => $seasonData['Overview'] ?? null,
                ],
            ]
        );

        // Fetch and sync episodes for this season
        $episodes = $service->fetchEpisodes($series->metadata['media_server_id'], $seasonId);
        foreach ($episodes as $episodeData) {
            $this->syncEpisode($integration, $playlist, $service, $series, $season, $episodeData);
            $this->stats['episodes_synced']++;
        }
    }

    /**
     * Sync a single episode.
     */
    protected function syncEpisode(
        MediaServerIntegration $integration,
        Playlist $playlist,
        MediaServerService $service,
        Series $series,
        Season $season,
        array $episodeData
    ): void {
        $episodeId = $episodeData['Id'];
        $container = $service->getContainerExtension($episodeData);
        $streamUrl = $service->getStreamUrl($episodeId, $container);
        $imageUrl = $service->getImageUrl($episodeId, 'Primary');
        $runtimeSeconds = $service->ticksToSeconds($episodeData['RunTimeTicks'] ?? 0);

        Episode::updateOrCreate(
            [
                'playlist_id' => $playlist->id,
                'source_episode_id' => crc32("media-server-{$integration->id}-{$episodeId}"),
            ],
            [
                'title' => $episodeData['Name'] ?? 'Episode ' . ($episodeData['IndexNumber'] ?? '?'),
                'user_id' => $playlist->user_id,
                'series_id' => $series->id,
                'season_id' => $season->id,
                'episode_num' => $episodeData['IndexNumber'] ?? null,
                'season' => $season->season_number,
                'url' => $streamUrl,
                'container_extension' => $container,
                'import_batch_no' => $this->batchNo,
                'enabled' => true,
                'plot' => $episodeData['Overview'] ?? null,
                'cover' => $imageUrl,
                'info' => [
                    'media_server_id' => $episodeId,
                    'media_server_type' => $integration->type,
                    'duration_secs' => $runtimeSeconds,
                    'duration' => gmdate('H:i:s', $runtimeSeconds),
                ],
            ]
        );
    }

    /**
     * Ensure a category exists for the given genre.
     */
    protected function ensureCategory(Playlist $playlist, string $genreName): Category
    {
        $category = Category::where('playlist_id', $playlist->id)
            ->where('name', $genreName)
            ->first();

        if (!$category) {
            $category = Category::create([
                'name' => $genreName,
                'name_internal' => $genreName,
                'user_id' => $playlist->user_id,
                'playlist_id' => $playlist->id,
            ]);
            $this->stats['categories_created']++;
        }

        return $category;
    }
}
