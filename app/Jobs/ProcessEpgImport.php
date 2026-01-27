<?php

namespace App\Jobs;

use App\Enums\EpgSourceType;
use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Epg;
use App\Models\Job;
use App\Services\SchedulesDirectService;
use App\Traits\ProviderRequestDelay;
use Carbon\Carbon;
use Exception;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Throwable;
use XMLReader;

class ProcessEpgImport implements ShouldQueue
{
    use ProviderRequestDelay;
    use Queueable;

    // To prevent errors when processing large files, limit imported channels to 50,000
    // NOTE: this only applies to M3U+ files
    //       Xtream API files are not limited
    public $maxItems = 50000;

    // Don't retry the job on failure
    public $tries = 1;

    // Default user agent to use for HTTP requests
    // Used when user agent is not set in the EPG
    public $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36';

    // Delete the job if the model is missing
    public $deleteWhenMissingModels = true;

    // Giving a timeout of 30 minutes to the Job to process the file
    public $timeout = 60 * 30;

    /**
     * Sanitize UTF-8 string to remove invalid sequences
     */
    private function sanitizeUtf8(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        // Convert to UTF-8, replacing invalid sequences
        $sanitized = mb_convert_encoding($value, 'UTF-8', 'UTF-8');

        // Remove control characters except newlines and tabs
        $sanitized = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $sanitized);

        return $sanitized;
    }

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Epg $epg,
        public ?bool $force = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(SchedulesDirectService $service): void
    {
        if (! $this->force) {
            // Don't update if currently processing
            if ($this->epg->processing) {
                return;
            }

            // Check if auto sync is enabled, or the EPG hasn't been synced yet
            if (! $this->epg->auto_sync && $this->epg->synced) {
                return;
            }
        }

        // Update the EPG status to processing
        $this->epg->update([
            'processing' => true,
            'status' => Status::Processing,
            'processing_started_at' => now(),
            'processing_phase' => 'import',
            'errors' => null,
            'progress' => 0,
        ]);

        // Flag job start time
        $start = now();

        // Process EPG XMLTV based on standard format
        // Info: https://wiki.xmltv.org/index.php/XMLTVFormat
        try {
            $epg = $this->epg;
            $epgId = $epg->id;
            $userId = $epg->user_id;
            $batchNo = Str::uuid7()->toString();

            $channelReader = null;
            $filePath = null;
            if ($epg->source_type === EpgSourceType::SCHEDULES_DIRECT) {
                if (! $epg->hasSchedulesDirectCredentials()) {
                    // Log the exception
                    logger()->error("Error processing \"{$this->epg->name}\"");

                    // Send notification
                    $error = 'Invalid SchedulesDirect credentials. Unable to get results from the API. Please check the credentials and try again.';
                    Notification::make()
                        ->danger()
                        ->title("Error processing \"{$this->epg->name}\"")
                        ->body('Please view your notifications for details.')
                        ->broadcast($this->epg->user);
                    Notification::make()
                        ->danger()
                        ->title("Error processing \"{$this->epg->name}\"")
                        ->body($error)
                        ->sendToDatabase($this->epg->user);

                    // Update the EPG
                    $this->epg->update([
                        'status' => Status::Failed,
                        'synced' => now(),
                        'errors' => $error,
                        'progress' => 100,
                        'processing' => false,
                        'processing_started_at' => null,
                        'processing_phase' => null,
                    ]);

                    // Fire the epg synced event
                    event(new SyncCompleted($this->epg));

                    return;
                } else {
                    // Sync the EPG data from SchedulesDirect
                    // Notify user we're starting the sync...
                    Notification::make()
                        ->info()
                        ->title('Starting SchedulesDirect Data Sync')
                        ->body("SchedulesDirect Data Sync started for EPG \"{$epg->name}\".")
                        ->broadcast($epg->user)
                        ->sendToDatabase($epg->user);

                    $shouldSync = true;
                    if (! $this->force) {
                        // If not forcing, check last modified time
                        $lastModified = Storage::disk('local')->exists($epg->file_path)
                            ? Storage::disk('local')->lastModified($epg->file_path)
                            : null;

                        if ($lastModified) {
                            $lastModifiedTime = Carbon::createFromTimestamp($lastModified);
                            $lastModifiedTime->addMinutes(10); // Add 10 minutes to last modified time
                            if (! $lastModifiedTime->isPast()) { // If modified within the last 10 minutes, skip
                                $shouldSync = false;
                            }
                        }
                    }
                    if ($shouldSync) {
                        $service->syncEpgData($epg);
                    }

                    // Calculate the time taken to complete the import
                    $completedIn = $start->diffInSeconds(now());
                    $completedInRounded = round($completedIn, 2);

                    // Notify user of success
                    Notification::make()
                        ->success()
                        ->title('SchedulesDirect Data Synced')
                        ->body("SchedulesDirect Data Synced successfully for EPG \"{$epg->name}\". Completed in {$completedInRounded} seconds. Now parsing data and generating EPG cache...")
                        ->broadcast($epg->user)
                        ->sendToDatabase($epg->user);

                    // After syncing, the XML file should be available
                    if (Storage::disk('local')->exists($epg->file_path)) {
                        $filePath = Storage::disk('local')->path($epg->file_path);
                    }
                }
            } elseif ($epg->url && str_starts_with($epg->url, 'http')) {
                // Normalize the EPG url and get the filename
                $url = str($epg->url)->replace(' ', '%20');

                // We need to grab the file contents first and set to temp file
                $verify = ! $epg->disable_ssl_verification;
                $userAgent = empty($epg->user_agent) ? $this->userAgent : $epg->user_agent;

                // Make sure the directory exists
                Storage::disk('local')->makeDirectory($epg->folder_path);

                // Get the file path
                $filePath = Storage::disk('local')->path($epg->file_path);

                // If the file exists, delete it
                if (Storage::disk('local')->exists($epg->file_path)) {
                    Storage::disk('local')->delete($epg->file_path);
                }
                $response = $this->withProviderThrottling(fn () => Http::withUserAgent($userAgent)
                    ->sink($filePath)
                    ->withOptions(['verify' => $verify])
                    ->timeout(60 * 5) // set timeout to five minutes
                    ->throw()->get($url->toString()));

                if ($response->ok() && Storage::disk('local')->exists($epg->file_path)) {
                    // Update the file path
                    $filePath = Storage::disk('local')->path($epg->file_path);
                } else {
                    $filePath = null;
                }
            } else {
                // Get uploaded file contents
                if ($epg->uploads && Storage::disk('local')->exists($epg->uploads)) {
                    $filePath = Storage::disk('local')->path($epg->uploads);
                } elseif ($epg->url) {
                    $filePath = $epg->url;
                }
            }

            // Update progress
            $epg->update(['progress' => 5]); // set to 5% to start

            // If we have XML data, let's process it
            if ($filePath) {
                // Setup the XML readers
                $channelReader = new XMLReader;
                $channelReader->open('compress.zlib://'.$filePath);
            } else {
                // Log the exception
                logger()->error("Error processing \"{$this->epg->name}\"");

                // Send notification
                $error = 'Invalid EPG file. Unable to read or download your EPG file. Please check the URL or uploaded file and try again.';
                Notification::make()
                    ->danger()
                    ->title("Error processing \"{$this->epg->name}\"")
                    ->body('Please view your notifications for details.')
                    ->broadcast($this->epg->user);
                Notification::make()
                    ->danger()
                    ->title("Error processing \"{$this->epg->name}\"")
                    ->body($error)
                    ->sendToDatabase($this->epg->user);

                // Update the EPG
                $this->epg->update([
                    'status' => Status::Failed,
                    'synced' => now(),
                    'errors' => $error,
                    'progress' => 100,
                    'processing' => false,
                ]);

                // Fire the epg synced event
                event(new SyncCompleted($this->epg));

                return;
            }

            // If reader valid, process the data!
            if ($channelReader) {
                // Default data structures
                $defaultChannelData = [
                    'name' => null,
                    'display_name' => null,
                    'lang' => null,
                    'icon' => null,
                    'channel_id' => null,
                    'epg_id' => $epgId,
                    'user_id' => $userId,
                    'import_batch_no' => $batchNo,
                    'additional_display_names' => null,
                ];

                // Update progress
                $epg->update(['progress' => 10]);
                $channelCount = 0;
                $programmeCount = 0;

                // Create a lazy collection to process the XML data
                LazyCollection::make(function () use (&$programmeCount, &$channelCount, $channelReader, $defaultChannelData) {
                    // Loop through the XML data
                    $channelCount = 0;
                    while (@$channelReader->read()) {
                        // Limit the number of items to process
                        if ($channelCount >= $this->maxItems) {
                            break;
                        }

                        // Only consider XML elements and channel nodes
                        if ($channelReader->nodeType == XMLReader::ELEMENT && $channelReader->name === 'channel') {
                            // Get the channel id
                            $channelId = trim($channelReader->getAttribute('id'));

                            // Setup parser for inner nodes
                            $innerXML = $channelReader->readOuterXml();
                            $innerReader = new XMLReader;
                            $innerReader->xml($innerXML);

                            // Set the default data
                            $elementData = [
                                ...$defaultChannelData,
                            ];

                            // Get the node data
                            $additionalDisplayNames = [];
                            while (@$innerReader->read()) {
                                if ($innerReader->nodeType == XMLReader::ELEMENT) {
                                    switch ($innerReader->name) {
                                        case 'channel':
                                            $elementData['channel_id'] = $this->sanitizeUtf8($channelId);
                                            break;
                                        case 'display-name':
                                            if (! $elementData['display_name']) {
                                                // Only use the first display-name element (could be multiple)
                                                $rawDisplayName = trim($innerReader->readString());
                                                $elementData['name'] = $this->sanitizeUtf8(Str::limit($rawDisplayName, 255));
                                                $elementData['display_name'] = $this->sanitizeUtf8($rawDisplayName);
                                                $elementData['lang'] = trim($innerReader->getAttribute('lang'));
                                            } else {
                                                // If we already have a display name, add to additional display names
                                                $additionalDisplayNames[] = $this->sanitizeUtf8(trim($innerReader->readString()));
                                            }
                                            break;
                                        case 'icon':
                                            $elementData['icon'] = trim($innerReader->getAttribute('src'));
                                            break;
                                    }
                                }
                            }
                            if (count($additionalDisplayNames) > 0) {
                                $elementData['additional_display_names'] = json_encode($additionalDisplayNames);
                            }

                            // Close the inner XMLReader
                            $innerReader->close();

                            // Only return valid channels
                            if ($elementData['channel_id']) {
                                $channelCount++;
                                yield $elementData;
                            }
                        }
                        if ($channelReader->nodeType == XMLReader::ELEMENT && $channelReader->name === 'programme') {
                            // Increment the programme count
                            $programmeCount++;
                        }
                    }
                })->chunk(50)->each(function (LazyCollection $chunk) use ($epg, $batchNo) {
                    Job::create([
                        'title' => "Processing import for EPG: {$epg->name}",
                        'batch_no' => $batchNo,
                        'payload' => $chunk->toArray(),
                        'variables' => [
                            'epgId' => $epg->id,
                        ],
                    ]);
                });

                // Close the XMLReaders, all done!
                $channelReader->close();

                // Update progress
                $epg->update([
                    'progress' => 15,
                    'channel_count' => $channelCount,
                    'programme_count' => $programmeCount,
                ]);

                // Get the jobs for the batch
                $jobs = [];
                $batchCount = Job::where('batch_no', $batchNo)->count();
                $jobsBatch = Job::where('batch_no', $batchNo)->select('id')->cursor();
                $jobsBatch->chunk(100)->each(function ($chunk) use (&$jobs, $batchCount) {
                    $jobs[] = new ProcessEpgImportChunk($chunk->pluck('id')->toArray(), $batchCount);
                });

                // Run completion after channels imported
                $jobs[] = new ProcessEpgImportComplete($userId, $epgId, $batchNo, $start);
                Bus::chain($jobs)
                    ->onConnection('redis') // force to use redis connection
                    ->onQueue('import')
                    ->catch(function (Throwable $e) use ($epg) {
                        $error = "Error processing \"{$epg->name}\": {$e->getMessage()}";
                        Notification::make()
                            ->danger()
                            ->title("Error processing \"{$epg->name}\"")
                            ->body('Please view your notifications for details.')
                            ->broadcast($epg->user);
                        Notification::make()
                            ->danger()
                            ->title("Error processing \"{$epg->name}\"")
                            ->body($error)
                            ->sendToDatabase($epg->user);
                        $epg->update([
                            'status' => Status::Failed,
                            'synced' => now(),
                            'errors' => $error,
                            'progress' => 100,
                            'processing' => false,
                            'processing_started_at' => null,
                            'processing_phase' => null,
                        ]);
                        event(new SyncCompleted($epg));
                    })->dispatch();
            } else {
                // Log the exception
                logger()->error("Error processing \"{$this->epg->name}\"");

                // Send notification
                $error = 'Invalid EPG file. Unable to read or download your EPG file. Please check the URL or uploaded file and try again.';
                Notification::make()
                    ->danger()
                    ->title("Error processing \"{$this->epg->name}\"")
                    ->body('Please view your notifications for details.')
                    ->broadcast($this->epg->user);
                Notification::make()
                    ->danger()
                    ->title("Error processing \"{$this->epg->name}\"")
                    ->body($error)
                    ->sendToDatabase($this->epg->user);

                // Update the EPG
                $this->epg->update([
                    'status' => Status::Failed,
                    'synced' => now(),
                    'errors' => $error,
                    'progress' => 100,
                    'processing' => false,
                    'processing_started_at' => null,
                    'processing_phase' => null,
                ]);

                // Fire the epg synced event
                event(new SyncCompleted($this->epg));
            }
        } catch (Exception $e) {
            // Log the exception
            logger()->error("Error processing \"{$this->epg->name}\": {$e->getMessage()}");

            // Send notification
            Notification::make()
                ->danger()
                ->title("Error processing \"{$this->epg->name}\"")
                ->body('Please view your notifications for details.')
                ->broadcast($this->epg->user);
            Notification::make()
                ->danger()
                ->title("Error processing \"{$this->epg->name}\"")
                ->body($e->getMessage())
                ->sendToDatabase($this->epg->user);

            // Update the EPG
            $this->epg->update([
                'status' => Status::Failed,
                'synced' => now(),
                'errors' => $e->getMessage(),
                'progress' => 100,
                'processing' => false,
                'processing_started_at' => null,
                'processing_phase' => null,
            ]);

            // Fire the epg synced event
            event(new SyncCompleted($this->epg));
        }

    }
}
