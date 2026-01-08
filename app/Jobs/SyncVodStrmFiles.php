<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\StrmFileMapping;
use App\Services\NfoService;
use App\Services\PlaylistService;
use App\Settings\GeneralSettings;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class SyncVodStrmFiles implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public bool $notify = true,
        public ?Channel $channel = null,
        public ?Collection $channels = null,
        public ?Playlist $playlist = null,
    ) {
        // Run file synces on the dedicated queue
        $this->onQueue('file_sync');
    }

    /**
     * Execute the job.
     */
    public function handle(GeneralSettings $settings): void
    {
        try {
            // Get global sync settings
            $global_sync_settings = [
                'enabled' => $settings->vod_stream_file_sync_enabled ?? false,
                'include_season' => $settings->vod_stream_file_sync_include_season ?? true,
                'sync_location' => $settings->vod_stream_file_sync_location ?? null,
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

            // NFO service for generating movie.nfo files
            $nfoService = ($global_sync_settings['generate_nfo'] ?? false) ? app(NfoService::class) : null;

            // Setup our channels to sync
            $channels = $this->channels ?? collect();
            if ($this->channel) {
                $this->channel->load('group');
                $channels->push($this->channel);
            } elseif ($this->playlist) {
                $channels = $this->playlist->channels()
                    ->where([
                        ['is_vod', true],
                        ['enabled', true],
                        ['source_id', '!=', null],
                    ])
                    ->with('group')
                    ->get();
            }

            // PERFORMANCE OPTIMIZATION: Bulk load all existing mappings for these channels
            // This reduces N queries (one per channel) to 1 query for all channels
            $syncLocation = rtrim($global_sync_settings['sync_location'] ?? '', '/');
            $mappingCache = null;
            if ($channels->count() > 1 && ! empty($syncLocation)) {
                $channelIds = $channels->pluck('id')->toArray();
                $mappingCache = StrmFileMapping::bulkLoadForSyncables(
                    Channel::class,
                    $channelIds,
                    $syncLocation
                );
            }

            // Loop through each channel and sync
            foreach ($channels as $channel) {
                $sync_settings = array_merge($global_sync_settings, $channel->sync_settings ?? []);
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
                if (in_array('group', $pathStructure)) {
                    // Note: $channel->group is a string column (not a relation) containing the group name
                    // Use the group column value directly, or fall back to the related Group model
                    $groupModel = $channel->getRelation('group');
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
                    $channelMapping = $mappingCache[$channel->id] ?? null;
                    $nfoService->generateMovieNfo($channel, $filePath, $channelMapping);
                }
            }

            // Clean up orphaned files for disabled/deleted channels
            // Run cleanup whenever we're syncing with a valid sync location
            if ($syncLocation = $global_sync_settings['sync_location'] ?? null) {
                StrmFileMapping::cleanupOrphaned(
                    Channel::class,
                    $syncLocation
                );

                // Clean up empty directories after orphaned cleanup
                StrmFileMapping::cleanupEmptyDirectoriesInLocation($syncLocation);
            }
        } catch (\Exception $e) {
            // Log the exception or handle it as needed
            Log::error('Error syncing VOD .strm files: '.$e->getMessage());
        }
    }
}
