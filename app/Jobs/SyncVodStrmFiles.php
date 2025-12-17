<?php

namespace App\Jobs;

use App\Facades\ProxyFacade;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\StrmFileMapping;
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
        //
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
            ];

            // Setup our channels to sync
            $channels = $this->channels ?? collect();
            if ($this->channel) {
                $channels->push($this->channel);
            } elseif ($this->playlist) {
                $channels = $this->playlist->channels()
                    ->where([
                        ['is_vod', true],
                        ['enabled', true],
                        ['source_id', '!=', null],
                    ])
                    ->get();
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
                    $group = $channel->group;
                    $groupName = $group?->name ?? $group?->name_internal ?? 'Uncategorized';
                    $groupName = $applyNameFilter($groupName);
                    $group = $cleanSpecialChars
                        ? PlaylistService::makeFilesystemSafe($groupName, $replaceChar)
                        : PlaylistService::makeFilesystemSafe($groupName);
                    $groupPath = $path . '/' . $group;
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

                    // Add TMDB ID to folder name for Trash Guides compatibility
                    $tmdbId = $channel->info['tmdb_id'] ?? $channel->movie_data['tmdb_id'] ?? null;
                    if (! empty($tmdbId)) {
                        $titleFolder .= " {tmdb-{$tmdbId}}";
                    }

                    $titleFolder = $cleanSpecialChars
                        ? PlaylistService::makeFilesystemSafe($titleFolder, $replaceChar)
                        : PlaylistService::makeFilesystemSafe($titleFolder);
                    $titlePath = $path . '/' . $titleFolder;
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

                // Only add TMDB ID to filename if title folder is NOT created
                // (If title folder exists, TMDB ID is already in the folder name)
                if (in_array('tmdb_id', $filenameMetadata) && ! $titleFolderCreated) {
                    $tmdbId = $channel->info['tmdb_id'] ?? $channel->movie_data['tmdb_id'] ?? null;
                    if (! empty($tmdbId)) {
                        $bracket = $tmdbIdFormat === 'curly' ? ['{', '}'] : ['[', ']'];
                        $fileName .= " {$bracket[0]}tmdb-{$tmdbId}{$bracket[1]}";
                    }
                }

                // Clean the filename
                $fileName = $cleanSpecialChars
                    ? PlaylistService::makeFilesystemSafe($fileName, $replaceChar)
                    : PlaylistService::makeFilesystemSafe($fileName);

                // Remove consecutive replacement characters if enabled
                if ($removeConsecutiveChars && $replaceChar !== 'remove') {
                    $char = $replaceChar === 'space' ? ' ' : ($replaceChar === 'dash' ? '-' : ($replaceChar === 'underscore' ? '_' : '.'));
                    $fileName = preg_replace('/' . preg_quote($char, '/') . '{2,}/', $char, $fileName);
                }

                $fileName = "{$fileName}.strm";
                $filePath = $path . '/' . $fileName;

                // Generate the url
                $playlist = $this->playlist ?? $channel->getEffectivePlaylist();
                $extension = $channel->container_extension ?? 'mkv';
                $url = rtrim("/movie/{$playlist->user->name}/{$playlist->uuid}/" . $channel->id . "." . $extension, '.');
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

                // Use intelligent sync - handles create, rename, and URL updates
                StrmFileMapping::syncFile(
                    $channel,
                    $syncLocation,
                    $filePath,
                    $url,
                    $pathOptions
                );
            }

            // Clean up orphaned files for disabled/deleted channels
            // Run cleanup whenever we're syncing with a valid sync location
            if ($syncLocation = $global_sync_settings['sync_location'] ?? null) {
                StrmFileMapping::cleanupOrphaned(
                    Channel::class,
                    $syncLocation
                );
            }
        } catch (\Exception $e) {
            // Log the exception or handle it as needed
            Log::error('Error syncing VOD .strm files: ' . $e->getMessage());
        }
    }
}
