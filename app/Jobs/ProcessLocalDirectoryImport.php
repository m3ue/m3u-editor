<?php

namespace App\Jobs;

use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\Season;
use App\Models\Series;
use App\Services\PlaylistService;
use Carbon\Carbon;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class ProcessLocalDirectoryImport implements ShouldQueue
{
    use Queueable;

    // Don't retry the job on failure
    public $tries = 1;

    // Giving a timeout of 30 minutes to the Job to process the directory
    public $timeout = 60 * 30;

    // Video file extensions to import
    private array $videoExtensions = ['.mp4', '.mkv', '.avi', '.mov', '.m4v', '.wmv', '.flv', '.webm', '.ts', '.m2ts'];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Playlist $playlist,
        public string $basePath,
        public string $importType, // 'vod' or 'series'
        public array $options = []
    ) {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Flag job start time
        $start = now();

        try {
            // Update the playlist status to processing
            $this->playlist->update([
                'processing' => true,
                'status' => Status::Processing,
                'errors' => null,
                'progress' => 0,
            ]);

            // Validate base path exists
            if (!is_dir($this->basePath)) {
                throw new Exception("Directory not found: {$this->basePath}");
            }

            // Process based on import type
            if ($this->importType === 'vod') {
                $this->importVodFromDirectory();
            } else {
                $this->importSeriesFromDirectory();
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
            $message = "Successfully imported {$this->importType} content from local directory in {$completedInRounded} seconds.";
            Notification::make()
                ->success()
                ->title('Local Directory Import Completed')
                ->body($message)
                ->broadcast($this->playlist->user);
            Notification::make()
                ->success()
                ->title('Local Directory Import Completed')
                ->body($message)
                ->sendToDatabase($this->playlist->user);

            // Fire the playlist synced event
            event(new SyncCompleted($this->playlist));
        } catch (Exception $e) {
            $this->sendError('Local directory import failed', $e->getMessage());
        }
    }

    /**
     * Import VOD content from directory
     */
    private function importVodFromDirectory(): void
    {
        $importedCount = 0;
        $batchNo = Str::orderedUuid()->toString();

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->basePath, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            $this->playlist->update(['progress' => 10]);

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $extension = strtolower($file->getExtension());
                if (!in_array('.' . $extension, $this->videoExtensions)) {
                    continue;
                }

                // Extract group from parent directory
                $relativePath = str_replace($this->basePath, '', $file->getPath());
                $relativePath = trim($relativePath, '/\\');
                $groupName = $relativePath ?: 'Uncategorized';

                // Create or get group
                $group = Group::firstOrCreate([
                    'name_internal' => $groupName,
                    'playlist_id' => $this->playlist->id,
                    'user_id' => $this->playlist->user_id,
                    'custom' => false,
                ], [
                    'name' => $groupName,
                    'import_batch_no' => $batchNo,
                ]);

                // Create VOD channel
                $title = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                $url = 'file://' . $file->getRealPath();

                Channel::updateOrCreate([
                    'title' => $title,
                    'playlist_id' => $this->playlist->id,
                    'group_internal' => $groupName,
                    'name' => $title,
                ], [
                    'url' => $url,
                    'group' => $groupName,
                    'group_id' => $group->id,
                    'user_id' => $this->playlist->user_id,
                    'enabled' => $this->options['auto_enable'] ?? true,
                    'is_vod' => true,
                    'container_extension' => $extension,
                    'source_id' => md5($url),
                    'import_batch_no' => $batchNo,
                ]);

                $importedCount++;
            }

            $this->playlist->update(['progress' => 90]);

            Log::info("Imported {$importedCount} VOD files from local directory for playlist {$this->playlist->id}");
        } catch (Exception $e) {
            throw new Exception("Error importing VOD content: {$e->getMessage()}");
        }
    }

    /**
     * Import Series content from directory
     */
    private function importSeriesFromDirectory(): void
    {
        $importedSeriesCount = 0;
        $importedEpisodeCount = 0;

        try {
            // Determine category
            $categoryName = $this->options['default_category'] ?? 'Imported Series';

            // Create category
            $category = Category::firstOrCreate([
                'name_internal' => $categoryName,
                'playlist_id' => $this->playlist->id,
            ], [
                'name' => $categoryName,
                'user_id' => $this->playlist->user_id,
                'enabled' => true,
            ]);

            $this->playlist->update(['progress' => 10]);

            // Scan for series directories
            $seriesDirs = glob($this->basePath . '/*', GLOB_ONLYDIR);

            if (empty($seriesDirs)) {
                throw new Exception("No series directories found in: {$this->basePath}");
            }

            $totalSeries = count($seriesDirs);
            $processedSeries = 0;

            foreach ($seriesDirs as $seriesDir) {
                $seriesName = basename($seriesDir);

                // Create series
                $series = Series::firstOrCreate([
                    'name' => $seriesName,
                    'playlist_id' => $this->playlist->id,
                ], [
                    'user_id' => $this->playlist->user_id,
                    'category_id' => $category->id,
                    'enabled' => $this->options['auto_enable'] ?? true,
                ]);

                $importedSeriesCount++;

                // Scan for season directories
                $seasonDirs = glob($seriesDir . '/Season *', GLOB_ONLYDIR);

                // If no season directories, check for episodes directly in series folder
                if (empty($seasonDirs)) {
                    $seasonDirs = [$seriesDir];
                }

                foreach ($seasonDirs as $seasonDir) {
                    // Extract season number
                    $seasonNum = 1;
                    if (preg_match('/Season\s+(\d+)/i', basename($seasonDir), $matches)) {
                        $seasonNum = (int)$matches[1];
                    }

                    // Create season
                    $season = Season::firstOrCreate([
                        'series_id' => $series->id,
                        'season_number' => $seasonNum,
                    ], [
                        'playlist_id' => $this->playlist->id,
                        'user_id' => $this->playlist->user_id,
                    ]);

                    // Scan for episode files
                    $episodeFiles = [];
                    foreach ($this->videoExtensions as $ext) {
                        $files = glob($seasonDir . '/*' . $ext);
                        $episodeFiles = array_merge($episodeFiles, $files);
                    }

                    foreach ($episodeFiles as $episodeFile) {
                        $filename = pathinfo($episodeFile, PATHINFO_FILENAME);

                        // Parse episode number and title
                        $episodeNum = 1;
                        $episodeTitle = $filename;

                        if (preg_match('/S\d+E(\d+)\s*-?\s*(.*)/', $filename, $matches)) {
                            $episodeNum = (int)$matches[1];
                            $episodeTitle = trim($matches[2]) ?: "Episode $episodeNum";
                        } elseif (preg_match('/E(\d+)\s*-?\s*(.*)/', $filename, $matches)) {
                            $episodeNum = (int)$matches[1];
                            $episodeTitle = trim($matches[2]) ?: "Episode $episodeNum";
                        } elseif (preg_match('/(\d+)\s*-?\s*(.*)/', $filename, $matches)) {
                            $episodeNum = (int)$matches[1];
                            $episodeTitle = trim($matches[2]) ?: "Episode $episodeNum";
                        }

                        Episode::updateOrCreate([
                            'season_id' => $season->id,
                            'episode_num' => $episodeNum,
                        ], [
                            'title' => $episodeTitle,
                            'url' => 'file://' . realpath($episodeFile),
                            'container_extension' => pathinfo($episodeFile, PATHINFO_EXTENSION),
                            'playlist_id' => $this->playlist->id,
                            'user_id' => $this->playlist->user_id,
                            'enabled' => $this->options['auto_enable'] ?? true,
                            'season' => $seasonNum,
                        ]);

                        $importedEpisodeCount++;
                    }
                }

                $processedSeries++;
                $progress = 10 + (int)(($processedSeries / $totalSeries) * 80);
                $this->playlist->update(['progress' => $progress]);
            }

            Log::info("Imported {$importedSeriesCount} series with {$importedEpisodeCount} episodes from local directory for playlist {$this->playlist->id}");
        } catch (Exception $e) {
            throw new Exception("Error importing series content: {$e->getMessage()}");
        }
    }

    /**
     * Send error notification
     */
    private function sendError(string $message, string $error): void
    {
        // Log the exception
        Log::error("Error processing local directory import for \"{$this->playlist->name}\": $error");

        // Send notification
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

        // Update the playlist
        $this->playlist->update([
            'status' => Status::Failed,
            'synced' => now(),
            'errors' => $error,
            'progress' => 100,
            'processing' => false,
        ]);

        // Fire the playlist synced event
        event(new SyncCompleted($this->playlist));
    }
}