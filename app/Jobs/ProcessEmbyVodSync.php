<?php

namespace App\Jobs;

use App\Enums\PlaylistSourceType;
use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Services\EmbyService;
use Carbon\Carbon;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ProcessEmbyVodSync implements ShouldQueue
{
    use Queueable;

    public $tries = 1;
    public $timeout = 60 * 60; // 1 hour timeout

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public string $libraryId,
        public string $libraryName,
        public bool $useDirectPath = false,
        public bool $autoEnable = true,
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $start = now();

        try {
            // Update playlist status and set source type
            $this->playlist->update([
                'processing' => true,
                'status' => Status::Processing,
                'errors' => null,
                'progress' => 0,
                'source_type' => PlaylistSourceType::Emby,
            ]);

            $embyService = new EmbyService();

            if (!$embyService->isConfigured()) {
                throw new Exception('Emby server is not configured. Please configure it in Settings.');
            }

            // Test connection
            $connectionTest = $embyService->testConnection();
            if (!$connectionTest['success']) {
                throw new Exception('Failed to connect to Emby server: ' . $connectionTest['error']);
            }

            $this->playlist->update(['progress' => 10]);

            // Fetch movies from library
            Log::info('Fetching movies from Emby library', [
                'library_id' => $this->libraryId,
                'library_name' => $this->libraryName,
            ]);
            
            $movies = $embyService->getLibraryItems($this->libraryId, 'Movie');
            
            Log::info('Emby movies fetch result', [
                'count' => count($movies),
                'library_id' => $this->libraryId,
                'library_name' => $this->libraryName,
            ]);
            
            if (empty($movies)) {
                throw new Exception('No movies found in the selected library. Library ID: ' . $this->libraryId);
            }

            $totalMovies = count($movies);
            $importedCount = 0;
            $batchNo = Str::orderedUuid()->toString();

            // Create or get group for this library
            $group = Group::firstOrCreate([
                'name_internal' => $this->libraryName,
                'playlist_id' => $this->playlist->id,
                'user_id' => $this->playlist->user_id,
                'custom' => false,
            ], [
                'name' => $this->libraryName,
                'import_batch_no' => $batchNo,
            ]);

            // Process each movie
            foreach ($movies as $index => $movie) {
                try {
                    Log::debug('Processing movie', [
                        'name' => $movie['Name'] ?? 'Unknown',
                        'id' => $movie['Id'] ?? 'Unknown',
                    ]);
                    $this->processMovie($movie, $group, $batchNo, $embyService);
                    $importedCount++;
                } catch (Exception $e) {
                    Log::warning("Failed to import movie {$movie['Name']}: " . $e->getMessage(), [
                        'movie_id' => $movie['Id'] ?? 'Unknown',
                        'exception' => $e,
                    ]);
                }

                // Update progress
                $progress = 10 + (int)(($index + 1) / $totalMovies * 80);
                $this->playlist->update(['progress' => $progress]);
            }

            // Calculate completion time
            $completedIn = $start->diffInSeconds(now());
            $completedInRounded = round($completedIn, 2);

            // Update playlist status
            $this->playlist->update([
                'status' => Status::Completed,
                'synced' => now(),
                'errors' => null,
                'sync_time' => $completedIn,
                'progress' => 100,
                'processing' => false,
            ]);

            // Send success notification
            $message = "Successfully imported {$importedCount} movies from Emby library '{$this->libraryName}' in {$completedInRounded} seconds.";
            Notification::make()
                ->success()
                ->title('Emby VOD Sync Completed')
                ->body($message)
                ->broadcast($this->playlist->user);
            Notification::make()
                ->success()
                ->title('Emby VOD Sync Completed')
                ->body($message)
                ->sendToDatabase($this->playlist->user);

            event(new SyncCompleted($this->playlist));
        } catch (Exception $e) {
            $this->sendError('Emby VOD sync failed', $e->getMessage());
        }
    }

    /**
     * Process a single movie
     */
    private function processMovie(array $movie, Group $group, string $batchNo, EmbyService $embyService): void
    {
        $title = $movie['Name'] ?? 'Unknown';
        $embyId = $movie['Id'];

        // Determine URL - use direct path or streaming URL
        if ($this->useDirectPath) {
            $url = $embyService->getFilePath($movie);
            if ($url) {
                $url = 'file://' . $url;
                Log::debug('Using direct file path for movie', ['title' => $title, 'path' => $url]);
            } else {
                $url = $embyService->getStreamUrl($embyId);
                Log::debug('Direct path not available, using stream URL', ['title' => $title, 'url' => $url]);
            }
        } else {
            $url = $embyService->getStreamUrl($embyId);
            Log::debug('Using stream URL for movie', ['title' => $title, 'url' => $url]);
        }

        // Extract metadata
        $year = $movie['ProductionYear'] ?? null;
        $overview = $movie['Overview'] ?? null;
        $genres = isset($movie['Genres']) ? implode(', ', $movie['Genres']) : null;
        $rating = $movie['CommunityRating'] ?? null;
        $officialRating = $movie['OfficialRating'] ?? null;

        // Get poster image
        $posterUrl = $embyService->getImageUrl($embyId, 'Primary');
        $backdropUrl = $embyService->getImageUrl($embyId, 'Backdrop');

        // Get container extension
        $containerExtension = null;
        if (isset($movie['MediaSources'][0]['Container'])) {
            $containerExtension = $movie['MediaSources'][0]['Container'];
        }

        // Create or update VOD channel
        Log::debug('Creating/updating VOD channel', [
            'title' => $title,
            'source_id' => 'emby_' . $embyId,
            'url' => $url,
        ]);
        
        Channel::updateOrCreate([
            'source_id' => 'emby_' . $embyId,
            'playlist_id' => $this->playlist->id,
        ], [
            'title' => $title,
            'name' => $title,
            'url' => $url,
            'group' => $this->libraryName,
            'group_internal' => $this->libraryName,
            'group_id' => $group->id,
            'user_id' => $this->playlist->user_id,
            'enabled' => $this->autoEnable,
            'is_vod' => true,
            'logo_internal' => $posterUrl,
            'container_extension' => $containerExtension,
            'import_batch_no' => $batchNo,
            'info' => [
                'description' => $overview,
                'year' => $year,
                'genre' => $genres,
                'rating' => $rating,
                'official_rating' => $officialRating,
                'backdrop' => $backdropUrl,
                'emby_id' => $embyId,
            ],
        ]);
    }

    /**
     * Send error notification
     */
    private function sendError(string $message, string $error): void
    {
        Log::error("Error processing Emby VOD sync for \"{$this->playlist->name}\": $error");

        Notification::make()
            ->danger()
            ->title("Error: {$message}")
            ->body('Please view your notifications for details.')
            ->broadcast($this->playlist->user);
        Notification::make()
            ->danger()
            ->title("Error: {$message}")
            ->body($error)
            ->sendToDatabase($this->playlist->user);

        $this->playlist->update([
            'status' => Status::Failed,
            'synced' => now(),
            'errors' => $error,
            'progress' => 100,
            'processing' => false,
        ]);

        event(new SyncCompleted($this->playlist));
    }
}