<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\StreamFileSetting;
use App\Models\StrmFileMapping;
use App\Models\User;
use App\Services\NfoService;
use App\Services\PlaylistService;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class SyncVodStrmFiles implements ShouldQueue
{
    use Queueable;

    /**
     * Track sync locations that were processed for deferred cleanup
     */
    protected array $processedSyncLocations = [];

    /**
     * Batch size for processing VOD STRM files.
     * Smaller batches = less memory but more jobs.
     */
    public const BATCH_SIZE = 50;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public bool $notify = true,
        public ?Channel $channel = null,
        public ?Collection $channels = null,
        public ?Playlist $playlist = null,
        public bool $all_playlists = false,
        public ?int $playlist_id = null,
        public ?int $user_id = null,
        public ?int $batchOffset = null,
        public ?int $totalBatches = null,
        public ?int $currentBatch = null,
        public bool $isCleanupJob = false,
    ) {
        // Run file synces on the dedicated queue
        $this->onQueue('file_sync');
    }

    /**
     * Execute the job.
     */
    public function handle(GeneralSettings $settings): void
    {
        // Track sync locations for cleanup at the end
        $this->processedSyncLocations = [];

        try {
            // Cache the global StreamFileSetting if configured
            $globalStreamFileSetting = $settings->default_vod_stream_file_setting_id
                ? StreamFileSetting::find($settings->default_vod_stream_file_setting_id)
                : null;

            // Single-channel mode
            if ($this->channel) {
                $this->channel->load('group', 'streamFileSetting', 'playlist.user', 'customPlaylist.user');
                $channels = collect([$this->channel]);
                $this->syncChannels($channels, $settings, $globalStreamFileSetting, skipCleanup: false);
                $this->dispatchMediaServerRefresh($globalStreamFileSetting, $channels);

                return;
            }

            // Explicit channels mode
            if ($this->channels) {
                $channels = $this->channels instanceof Collection
                    ? $this->channels
                    : collect($this->channels);

                if ($channels->isEmpty()) {
                    return;
                }

                $this->syncChannels($channels, $settings, $globalStreamFileSetting, skipCleanup: false);
                $this->dispatchMediaServerRefresh($globalStreamFileSetting, $channels);

                return;
            }

            // Special cleanup job - runs after all batch jobs
            if ($this->isCleanupJob) {
                $this->performGlobalCleanup($settings);

                return;
            }

            // Batch processing mode
            if ($this->batchOffset !== null) {
                $this->processBatch($settings, $globalStreamFileSetting);

                return;
            }

            // Initial dispatch - calculate and dispatch batches
            $this->dispatchBatches();
        } catch (\Throwable $e) {
            // Log full exception with stack trace so failures are visible in logs
            Log::error('STRM Sync: Unhandled exception in SyncVodStrmFiles::handle', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Re-throw to allow job retry semantics to continue
            throw $e;
        }
    }

    /**
     * Dispatch first chain of batch jobs.
     */
    private function dispatchBatches(): void
    {
        $totalCount = $this->getBaseChannelsQuery()->count();

        if ($totalCount === 0) {
            Log::info('STRM Sync: No VOD channels to process');

            return;
        }

        $batchSize = self::BATCH_SIZE;
        $totalBatches = (int) ceil($totalCount / $batchSize);
        $jobsPerChain = CheckVodStrmProgress::JOBS_PER_CHAIN;

        Log::info('STRM Sync: Starting chain-based VOD dispatch', [
            'total_vod_channels' => $totalCount,
            'batch_size' => $batchSize,
            'total_batches' => $totalBatches,
            'jobs_per_chain' => $jobsPerChain,
        ]);

        $jobs = [];
        $jobsInFirstChain = min($jobsPerChain, $totalBatches);

        for ($batch = 0; $batch < $jobsInFirstChain; $batch++) {
            $offset = $batch * $batchSize;

            $jobs[] = new self(
                notify: false,
                all_playlists: $this->all_playlists,
                playlist_id: $this->resolvePlaylistId(),
                user_id: $this->resolveUserId(),
                batchOffset: $offset,
                totalBatches: $totalBatches,
                currentBatch: $batch + 1,
            );
        }

        // Add checker job at the end of the chain
        $jobs[] = new CheckVodStrmProgress(
            currentOffset: $jobsInFirstChain * $batchSize,
            totalChannels: $totalCount,
            notify: $this->notify,
            all_playlists: $this->all_playlists,
            playlist_id: $this->resolvePlaylistId(),
            user_id: $this->resolveUserId(),
            needsCleanup: true,
        );

        Bus::chain($jobs)->dispatch();
    }

    /**
     * Process a specific batch of channels.
     */
    private function processBatch(GeneralSettings $settings, ?StreamFileSetting $globalStreamFileSetting): void
    {
        $startTime = microtime(true);
        $processedCount = 0;

        Log::debug("STRM Sync: Processing VOD batch {$this->currentBatch}/{$this->totalBatches}", [
            'offset' => $this->batchOffset,
        ]);

        $channelIds = $this->getBaseChannelsQuery()
            ->orderBy('id')
            ->skip($this->batchOffset)
            ->take(self::BATCH_SIZE)
            ->pluck('id')
            ->toArray();

        foreach (array_chunk($channelIds, 25) as $chunkIds) {
            $channelChunk = Channel::query()
                ->whereIn('id', $chunkIds)
                ->with(['group', 'group.streamFileSetting', 'streamFileSetting', 'playlist.user', 'customPlaylist.user'])
                ->get();

            $this->syncChannels($channelChunk, $settings, $globalStreamFileSetting, skipCleanup: true);
            $processedCount += $channelChunk->count();

            unset($channelChunk);
            gc_collect_cycles();
        }

        $duration = round(microtime(true) - $startTime, 2);
        Log::debug("STRM Sync: VOD batch {$this->currentBatch}/{$this->totalBatches} completed in {$duration}s", [
            'processed' => $processedCount,
        ]);
    }

    /**
     * Process and sync a collection of VOD channels.
     */
    private function syncChannels(Collection $channels, GeneralSettings $settings, ?StreamFileSetting $globalStreamFileSetting, bool $skipCleanup = false): void
    {
        // Get the default sync location for bulk mapping cache
        $defaultSyncLocation = $globalStreamFileSetting?->location ?? $settings->vod_stream_file_sync_location ?? '';

        // PERFORMANCE OPTIMIZATION: Bulk load all existing mappings for these channels
        // This reduces N queries (one per channel) to 1 query for all channels
        $syncLocation = rtrim($defaultSyncLocation, '/');
        $mappingCache = null;
        if ($channels->count() > 1 && ! empty($syncLocation)) {
            $channelIds = $channels->pluck('id')->toArray();
            $mappingCache = StrmFileMapping::bulkLoadForSyncables(
                Channel::class,
                $channelIds,
                $syncLocation
            );
        }

        // NFO service instance (lazy-loaded per channel if needed)
        $nfoService = null;

        // Loop through each channel and sync
        foreach ($channels as $channel) {
            // Resolve settings with priority chain: Channel > Group > Global Profile > Legacy Settings
            $sync_settings = $this->resolveVodSyncSettings($channel, $settings, $globalStreamFileSetting);

            // Initialize NFO service if needed for this channel
            if (($sync_settings['generate_nfo'] ?? false) && ! $nfoService) {
                $nfoService = app(NfoService::class);
            }
            if (! $sync_settings['enabled'] ?? false) {
                continue;
            }

            // Get the path info - store original sync location for tracking
            $syncLocation = rtrim($sync_settings['sync_location'], '/');
            $path = $syncLocation;
            if (! is_dir($path)) {
                // Attempt to create the base sync location and restore files from mappings
                if (! @mkdir($path, 0755, true)) {
                    if ($this->notify) {
                        Notification::make()
                            ->danger()
                            ->title("Error sync .strm files for VOD channel \"{$channel->title}\"")
                            ->body("Sync location \"{$path}\" does not exist.")
                            ->broadcast($channel->user)
                            ->sendToDatabase($channel->user);
                    } else {
                        Log::error("Error sync .strm files for VOD channel \"{$channel->title}\": Sync location \"{$path}\" does not exist.");
                    }

                    return;
                }

                // If directory was created, attempt to restore files from DB mappings
                $restored = StrmFileMapping::restoreForSyncLocation($syncLocation);
                Log::info('STRM Sync: Created missing sync location and restored files', ['sync_location' => $syncLocation, 'restored' => $restored]);
            }

            // Get path structure and replacement character settings
            $pathStructure = $sync_settings['path_structure'] ?? ['group'];
            $replaceChar = $sync_settings['replace_char'] ?? 'space';
            $cleanSpecialChars = $sync_settings['clean_special_chars'] ?? false;
            $filenameMetadata = $sync_settings['filename_metadata'] ?? [];
            $tmdbIdFormat = $sync_settings['tmdb_id_format'] ?? 'square';
            $removeConsecutiveChars = $sync_settings['remove_consecutive_chars'] ?? false;

            // Get name filtering settings
            $nameFilterEnabled = $sync_settings['name_filter_enabled'] ?? false;
            $nameFilterPatterns = $sync_settings['name_filter_patterns'] ?? [];

            // Helper function to apply name filtering
            $applyNameFilter = function ($name) use ($nameFilterEnabled, $nameFilterPatterns) {
                if (! $nameFilterEnabled || empty($nameFilterPatterns)) {
                    return $name;
                }
                foreach ($nameFilterPatterns as $pattern) {
                    $name = str_replace($pattern, '', $name);
                }

                return trim($name);
            };

            // Create the group folder if enabled
            $groupModel = $channel->relationLoaded('group')
                ? $channel->getRelation('group')
                : $channel->group()->first();

            if (in_array('group', $pathStructure)) {
                // Note: $channel->group is a string column (not a relation) containing the group name
                // Use the group column value directly, or fall back to the related Group model
                $groupName = $channel->group ?? $groupModel?->name ?? $groupModel?->name_internal ?? 'Uncategorized';
                $groupName = $applyNameFilter($groupName);
                $groupFolder = $cleanSpecialChars
                    ? PlaylistService::makeFilesystemSafe($groupName, $replaceChar)
                    : PlaylistService::makeFilesystemSafe($groupName);
                $groupPath = $path.'/'.$groupFolder;
                if (! is_dir($groupPath)) {
                    mkdir($groupPath, 0777, true);
                }
                $path = $groupPath;
            }

            // Build the filename (apply name filtering to title)
            $title = $channel->title_custom ?? $channel->title;
            $title = $applyNameFilter($title);
            $fileName = $title;

            // Track if title folder is created (for TMDB ID placement logic)
            $titleFolderCreated = in_array('title', $pathStructure);

            // Create the VOD Title folder if enabled (with Trash Guides format support)
            if ($titleFolderCreated) {
                $titleFolder = $title;

                // Add year to folder name if available
                if (! empty($channel->year) && strpos($titleFolder, "({$channel->year})") === false) {
                    $titleFolder .= " ({$channel->year})";
                }

                // Add TMDB/IMDB ID to folder name for Trash Guides compatibility
                // Check multiple possible locations for IDs (priority: TMDB > IMDB)
                $tmdbId = $channel->info['tmdb_id']
                    ?? $channel->info['tmdb']
                    ?? $channel->movie_data['tmdb_id']
                    ?? $channel->movie_data['tmdb']
                    ?? null;
                $imdbId = $channel->info['imdb_id']
                    ?? $channel->info['imdb']
                    ?? $channel->movie_data['imdb_id']
                    ?? $channel->movie_data['imdb']
                    ?? null;
                // Ensure IDs are scalar values (not arrays)
                $tmdbId = is_scalar($tmdbId) ? $tmdbId : null;
                $imdbId = is_scalar($imdbId) ? $imdbId : null;

                $bracket = $tmdbIdFormat === 'curly' ? ['{', '}'] : ['[', ']'];
                if (! empty($tmdbId)) {
                    $titleFolder .= " {$bracket[0]}tmdb-{$tmdbId}{$bracket[1]}";
                } elseif (! empty($imdbId)) {
                    $titleFolder .= " {$bracket[0]}imdb-{$imdbId}{$bracket[1]}";
                }

                $titleFolder = $cleanSpecialChars
                    ? PlaylistService::makeFilesystemSafe($titleFolder, $replaceChar)
                    : PlaylistService::makeFilesystemSafe($titleFolder);
                $titlePath = $path.'/'.$titleFolder;
                if (! is_dir($titlePath)) {
                    mkdir($titlePath, 0777, true);
                }

                $path = $titlePath;
            }

            // Add metadata to filename
            if (in_array('year', $filenameMetadata) && ! empty($channel->year)) {
                // Only add year if it's not already in the title
                if (strpos($fileName, "({$channel->year})") === false) {
                    $fileName .= " ({$channel->year})";
                }
            }

            // Only add TMDB/IMDB ID to filename if title folder is NOT created
            // (If title folder exists, ID is already in the folder name)
            if (in_array('tmdb_id', $filenameMetadata) && ! $titleFolderCreated) {
                // Check multiple possible locations for IDs (priority: TMDB > IMDB)
                $tmdbId = $channel->info['tmdb_id']
                    ?? $channel->info['tmdb']
                    ?? $channel->movie_data['tmdb_id']
                    ?? $channel->movie_data['tmdb']
                    ?? null;
                $imdbId = $channel->info['imdb_id']
                    ?? $channel->info['imdb']
                    ?? $channel->movie_data['imdb_id']
                    ?? $channel->movie_data['imdb']
                    ?? null;
                // Ensure IDs are scalar values (not arrays)
                $tmdbId = is_scalar($tmdbId) ? $tmdbId : null;
                $imdbId = is_scalar($imdbId) ? $imdbId : null;

                $bracket = $tmdbIdFormat === 'curly' ? ['{', '}'] : ['[', ']'];
                if (! empty($tmdbId)) {
                    $fileName .= " {$bracket[0]}tmdb-{$tmdbId}{$bracket[1]}";
                } elseif (! empty($imdbId)) {
                    $fileName .= " {$bracket[0]}imdb-{$imdbId}{$bracket[1]}";
                }
            }

            // Add group suffix to filename if enabled
            if (in_array('group', $filenameMetadata)) {
                $groupSuffix = $channel->group ?? $groupModel?->name ?? $groupModel?->name_internal ?? 'Uncategorized';
                $groupSuffix = $applyNameFilter($groupSuffix);
                $fileName .= " - {$groupSuffix}";
            }

            // Clean the filename
            $fileName = $cleanSpecialChars
                ? PlaylistService::makeFilesystemSafe($fileName, $replaceChar)
                : PlaylistService::makeFilesystemSafe($fileName);

            // Remove consecutive replacement characters if enabled
            if ($removeConsecutiveChars && $replaceChar !== 'remove') {
                $char = $replaceChar === 'space' ? ' ' : ($replaceChar === 'dash' ? '-' : ($replaceChar === 'underscore' ? '_' : '.'));
                $fileName = preg_replace('/'.preg_quote($char, '/').'{2,}/', $char, $fileName);
            }

            $fileName = "{$fileName}.strm";
            $filePath = $path.'/'.$fileName;

            // Generate the url
            $playlist = $this->playlist ?? $channel->getEffectivePlaylist();
            $extension = $channel->container_extension ?? 'mkv';
            $url = rtrim("/movie/{$playlist->user->name}/{$playlist->uuid}/".$channel->id.'.'.$extension, '.');
            $url = PlaylistService::getBaseUrl($url);

            // Build path options for tracking changes
            $pathOptions = [
                'path_structure' => $pathStructure,
                'filename_metadata' => $filenameMetadata,
                'tmdb_id_format' => $tmdbIdFormat,
                'clean_special_chars' => $cleanSpecialChars,
                'replace_char' => $replaceChar,
                'remove_consecutive_chars' => $removeConsecutiveChars,
                'name_filter_enabled' => $nameFilterEnabled,
                'name_filter_patterns' => $nameFilterPatterns,
            ];

            // Use intelligent sync with pre-loaded cache - handles create, rename, and URL updates
            StrmFileMapping::syncFileWithCache(
                $channel,
                $syncLocation,
                $filePath,
                $url,
                $pathOptions,
                $mappingCache
            );

            // Generate movie NFO file if enabled (pass mapping for hash optimization)
            if ($nfoService) {
                $channelMapping = $mappingCache?->get($channel->id);
                $nfoOptions = [
                    'name_filter_enabled' => $nameFilterEnabled,
                    'name_filter_patterns' => $nameFilterPatterns,
                ];
                $nfoService->generateMovieNfo($channel, $filePath, $channelMapping, $nfoOptions);
            }

            // Track sync location for deferred cleanup
            if (! in_array($syncLocation, $this->processedSyncLocations, true)) {
                $this->processedSyncLocations[] = $syncLocation;
            }
        }

        if (! $skipCleanup) {
            $this->performCleanup();
        }
    }

    /**
     * Perform cleanup for all processed sync locations.
     */
    private function performCleanup(): void
    {
        foreach ($this->processedSyncLocations as $syncLocation) {
            StrmFileMapping::cleanupOrphaned(Channel::class, $syncLocation);
            StrmFileMapping::cleanupEmptyDirectoriesInLocation($syncLocation);
        }
    }

    /**
     * Perform global cleanup after all batches complete.
     */
    private function performGlobalCleanup(GeneralSettings $settings): void
    {
        $startTime = microtime(true);

        Log::info('STRM Sync: Starting global VOD cleanup');

        $syncLocations = StrmFileMapping::query()
            ->where('syncable_type', Channel::class)
            ->distinct()
            ->pluck('sync_location')
            ->toArray();

        foreach ($syncLocations as $syncLocation) {
            StrmFileMapping::cleanupOrphaned(Channel::class, $syncLocation);
            StrmFileMapping::cleanupEmptyDirectoriesInLocation($syncLocation);
        }

        $duration = round(microtime(true) - $startTime, 2);
        Log::info('STRM Sync: Global VOD cleanup completed', [
            'sync_locations' => count($syncLocations),
            'duration_seconds' => $duration,
        ]);

        $this->dispatchMediaServerRefreshForBulk($settings);

        if ($this->notify && $this->resolveUserId()) {
            $user = User::find($this->resolveUserId());
            if ($user) {
                Notification::make()
                    ->success()
                    ->title('STRM File Sync Completed')
                    ->body('All VOD STRM files have been synced.')
                    ->broadcast($user)
                    ->sendToDatabase($user);
            }
        }
    }

    /**
     * Dispatch media server refresh jobs for bulk VOD sync.
     */
    protected function dispatchMediaServerRefreshForBulk(GeneralSettings $settings): void
    {
        $integrationIds = collect();
        $playlistId = $this->resolvePlaylistId();
        $userId = $this->resolveUserId();

        if ($settings->default_vod_stream_file_setting_id) {
            $globalStreamFileSetting = StreamFileSetting::find($settings->default_vod_stream_file_setting_id);
            if ($globalStreamFileSetting?->refresh_media_server && $globalStreamFileSetting?->media_server_integration_id) {
                $integrationIds->push([
                    'id' => $globalStreamFileSetting->media_server_integration_id,
                    'delay' => $globalStreamFileSetting->refresh_delay_seconds ?? 5,
                ]);
            }
        }

        $channelStreamFileSettings = StreamFileSetting::query()
            ->forVod()
            ->where('refresh_media_server', true)
            ->whereNotNull('media_server_integration_id')
            ->whereHas('channels', function ($query) use ($playlistId, $userId) {
                $query->where('is_vod', true)->where('enabled', true);
                if ($userId) {
                    $query->where('user_id', $userId);
                }
                if (! $this->all_playlists && $playlistId) {
                    $query->where('playlist_id', $playlistId);
                }
            })
            ->get();

        foreach ($channelStreamFileSettings as $streamFileSetting) {
            if (! $integrationIds->contains('id', $streamFileSetting->media_server_integration_id)) {
                $integrationIds->push([
                    'id' => $streamFileSetting->media_server_integration_id,
                    'delay' => $streamFileSetting->refresh_delay_seconds ?? 5,
                ]);
            }
        }

        $groupStreamFileSettings = StreamFileSetting::query()
            ->forVod()
            ->where('refresh_media_server', true)
            ->whereNotNull('media_server_integration_id')
            ->whereHas('groups', function ($query) use ($playlistId, $userId) {
                if ($userId) {
                    $query->where('user_id', $userId);
                }
                if (! $this->all_playlists && $playlistId) {
                    $query->where('playlist_id', $playlistId);
                }
            })
            ->get();

        foreach ($groupStreamFileSettings as $streamFileSetting) {
            if (! $integrationIds->contains('id', $streamFileSetting->media_server_integration_id)) {
                $integrationIds->push([
                    'id' => $streamFileSetting->media_server_integration_id,
                    'delay' => $streamFileSetting->refresh_delay_seconds ?? 5,
                ]);
            }
        }

        foreach ($integrationIds as $integrationData) {
            $integration = MediaServerIntegration::find($integrationData['id']);
            if ($integration) {
                RefreshMediaServerLibraryJob::dispatch($integration, $this->notify)
                    ->delay(now()->addSeconds($integrationData['delay']));
            }
        }
    }

    /**
     * Build base query for VOD channels within current sync scope.
     */
    private function getBaseChannelsQuery()
    {
        $playlistId = $this->resolvePlaylistId();
        $userId = $this->resolveUserId();

        return Channel::query()
            ->where('is_vod', true)
            ->where('enabled', true)
            ->when($userId, function ($query) use ($userId) {
                $query->where('user_id', $userId);
            })
            ->when(! $this->all_playlists && $playlistId, function ($query) use ($playlistId) {
                $query->where('playlist_id', $playlistId);
            });
    }

    private function resolvePlaylistId(): ?int
    {
        return $this->playlist_id ?? $this->playlist?->id;
    }

    private function resolveUserId(): ?int
    {
        return $this->user_id ?? $this->playlist?->user_id;
    }

    /**
     * Dispatch media server refresh jobs for any StreamFileSettings that have refresh enabled.
     */
    protected function dispatchMediaServerRefresh(?StreamFileSetting $globalStreamFileSetting, $channels): void
    {
        $integrationIds = collect();

        // Check global setting
        if ($globalStreamFileSetting?->refresh_media_server && $globalStreamFileSetting?->media_server_integration_id) {
            $integrationIds->push([
                'id' => $globalStreamFileSetting->media_server_integration_id,
                'delay' => $globalStreamFileSetting->refresh_delay_seconds ?? 5,
            ]);
        }

        // Check channel-level and group-level settings
        foreach ($channels as $channel) {
            $streamFileSetting = $channel->streamFileSetting;

            // Check group-level if no channel-level setting
            if (! $streamFileSetting && $channel->relationLoaded('group')) {
                $groupModel = $channel->getRelation('group');
                $streamFileSetting = $groupModel?->streamFileSetting;
            }

            if ($streamFileSetting?->refresh_media_server && $streamFileSetting?->media_server_integration_id) {
                // Only add if not already in the collection
                if (! $integrationIds->contains('id', $streamFileSetting->media_server_integration_id)) {
                    $integrationIds->push([
                        'id' => $streamFileSetting->media_server_integration_id,
                        'delay' => $streamFileSetting->refresh_delay_seconds ?? 5,
                    ]);
                }
            }
        }

        // Dispatch refresh jobs for each unique integration
        foreach ($integrationIds as $integrationData) {
            $integration = MediaServerIntegration::find($integrationData['id']);
            if ($integration) {
                RefreshMediaServerLibraryJob::dispatch($integration, $this->notify)
                    ->delay(now()->addSeconds($integrationData['delay']));
            }
        }
    }

    /**
     * Resolve sync settings with priority chain: Channel > Group > Global Profile > Legacy Settings
     */
    protected function resolveVodSyncSettings(Channel $channel, GeneralSettings $settings, ?StreamFileSetting $globalStreamFileSetting): array
    {
        // Priority 1: Channel-level StreamFileSetting
        $streamFileSetting = $channel->streamFileSetting;

        // Priority 2: Group-level StreamFileSetting
        if (! $streamFileSetting) {
            // Note: $channel->group is a string column (not a relation) containing the group name
            //       Use the related Group model if loaded instead
            $groupModel = $channel->relationLoaded('group')
                ? $channel->getRelation('group')
                : $channel->group()->first();

            if ($groupModel) {
                $streamFileSetting = $groupModel->streamFileSetting;
            }
        }

        // Priority 3: Global StreamFileSetting
        if (! $streamFileSetting) {
            $streamFileSetting = $globalStreamFileSetting;
        }

        // If we have a StreamFileSetting model, use its settings
        if ($streamFileSetting) {
            $sync_settings = $streamFileSetting->toSyncSettings();

            // Allow channel-level sync_location override
            if ($channel->sync_location) {
                $sync_settings['sync_location'] = $channel->sync_location;
            }

            return $sync_settings;
        }

        // Priority 4: Legacy settings from GeneralSettings (backwards compatibility)
        $legacy_sync_settings = $channel->sync_settings ?? [];

        $global_sync_settings = [
            'enabled' => $settings->vod_stream_file_sync_enabled ?? false,
            'include_season' => $settings->vod_stream_file_sync_include_season ?? true,
            'sync_location' => $channel->sync_location ?? $settings->vod_stream_file_sync_location ?? null,
            'path_structure' => $settings->vod_stream_file_sync_path_structure ?? ['group'],
            'filename_metadata' => $settings->vod_stream_file_sync_filename_metadata ?? [],
            'tmdb_id_format' => $settings->vod_stream_file_sync_tmdb_id_format ?? 'square',
            'clean_special_chars' => $settings->vod_stream_file_sync_clean_special_chars ?? false,
            'remove_consecutive_chars' => $settings->vod_stream_file_sync_remove_consecutive_chars ?? false,
            'replace_char' => $settings->vod_stream_file_sync_replace_char ?? 'space',
            'name_filter_enabled' => $settings->vod_stream_file_sync_name_filter_enabled ?? false,
            'name_filter_patterns' => $settings->vod_stream_file_sync_name_filter_patterns ?? [],
            'generate_nfo' => $settings->vod_stream_file_sync_generate_nfo ?? false,
        ];

        // Merge global settings with channel-specific legacy settings
        return array_merge($global_sync_settings, $legacy_sync_settings);
    }
}
