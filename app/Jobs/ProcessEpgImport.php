<?php

namespace App\Jobs;

use Exception;
use XMLReader;
use Throwable;
use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Epg;
use App\Models\Job;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;

class ProcessEpgImport implements ShouldQueue
{
    use Queueable;

    // To prevent errors when processing large files, limit imported channels to 50,000
    // NOTE: this only applies to M3U+ files
    //       Xtream API files are not limited
    public $maxItems = 50000;

    // Default user agent to use for HTTP requests
    // Used when user agent is not set in the EPG
    public $userAgent = 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13';

    // Delete the job if the model is missing
    public $deleteWhenMissingModels = true;

    // Giving a timeout of 10 minutes to the Job to process the file
    public $timeout = 60 * 10;

    /**
     * Create a new job instance.
     * 
     * @param Epg $epg
     */
    public function __construct(
        public Epg $epg,
        public ?bool $force = false,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (!$this->force) {
            // Don't update if currently processing
            if ($this->epg->processing) {
                return;
            }

            // Check if auto sync is enabled, or the EPG hasn't been synced yet
            if (!$this->epg->auto_sync && $this->epg->synced) {
                return;
            }
        }

        // Update the EPG status to processing
        $this->epg->update([
            'processing' => true,
            'status' => Status::Processing,
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
            if ($epg->url && str_starts_with($epg->url, 'http')) {
                // Normalize the EPG url and get the filename
                $url = str($epg->url)->replace(' ', '%20');

                // We need to grab the file contents first and set to temp file
                $verify = !$epg->disable_ssl_verification;
                $userAgent = empty($epg->user_agent) ? $this->userAgent : $epg->user_agent;
                $response = Http::withUserAgent($userAgent)
                    ->withOptions(['verify' => $verify])
                    ->timeout(60 * 5) // set timeout to five minues
                    ->throw()->get($url->toString());

                if ($response->ok()) {
                    // Remove previous saved files
                    Storage::disk('local')->deleteDirectory($epg->folder_path);

                    // Save the file to local storage
                    Storage::disk('local')->put(
                        $epg->file_path,
                        $response->body()
                    );

                    // Update the file path
                    $filePath = Storage::disk('local')->path($epg->file_path);
                }
            } else {
                // Get uploaded file contents
                if ($epg->uploads && Storage::disk('local')->exists($epg->uploads)) {
                    $filePath = Storage::disk('local')->path($epg->uploads);
                } else if ($epg->url) {
                    $filePath = $epg->url;
                }
            }

            // Update progress
            $epg->update(['progress' => 5]); // set to 5% to start

            // If we have XML data, let's process it
            if ($filePath) {
                // Setup the XML readers
                $channelReader = new XMLReader();
                $channelReader->open('compress.zlib://' . $filePath);
            } else {
                // Log the exception
                logger()->error("Error processing \"{$this->epg->name}\"");

                // Send notification
                $error = "Invalid EPG file. Unable to read or download your EPG file. Please check the URL or uploaded file and try again.";
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
                ];

                // Update progress
                $epg->update(['progress' => 10]);

                // Create a lazy collection to process the XML data
                LazyCollection::make(function () use ($channelReader, $defaultChannelData) {
                    // Loop through the XML data
                    $count = 0;
                    while (@$channelReader->read()) {
                        // Limit the number of items to process
                        if ($count >= $this->maxItems) {
                            break;
                        }

                        // Only consider XML elements and channel nodes
                        if ($channelReader->nodeType == XMLReader::ELEMENT && $channelReader->name === 'channel') {
                            // Get the channel id
                            $channelId = trim($channelReader->getAttribute('id'));

                            // Setup parser for inner nodes
                            $innerXML = $channelReader->readOuterXml();
                            $innerReader = new XMLReader();
                            $innerReader->xml($innerXML);

                            // Set the default data
                            $elementData = [
                                ...$defaultChannelData
                            ];

                            // Get the node data
                            while (@$innerReader->read()) {
                                if ($innerReader->nodeType == XMLReader::ELEMENT) {
                                    switch ($innerReader->name) {
                                        case 'channel':
                                            $elementData['channel_id'] = $channelId;
                                            break;
                                        case 'display-name':
                                            if (!$elementData['display_name']) {
                                                // Only use the first display-name element (could be multiple)
                                                $elementData['name'] = trim($innerReader->readString());
                                                $elementData['display_name'] = trim($innerReader->readString());
                                                $elementData['lang'] = trim($innerReader->getAttribute('lang'));
                                            }
                                            break;
                                        case 'icon':
                                            $elementData['icon'] = trim($innerReader->getAttribute('src'));
                                            break;
                                    }
                                }
                            }

                            // Close the inner XMLReader
                            $innerReader->close();

                            // Only return valid channels
                            if ($elementData['channel_id']) {
                                $count++;
                                yield $elementData;
                            }
                        }
                    }
                })->chunk(50)->each(function (LazyCollection $chunk) use ($epg, $batchNo) {
                    Job::create([
                        'title' => "Processing import for EPG: {$epg->name}",
                        'batch_no' => $batchNo,
                        'payload' => $chunk->toArray(),
                        'variables' => [
                            'epgId' => $epg->id,
                        ]
                    ]);
                });

                // Close the XMLReaders, all done!
                $channelReader->close();

                // Update progress
                $epg->update(['progress' => 15]);

                // Get the jobs for the batch
                $jobs = [];
                $batchCount = Job::where('batch_no', $batchNo)->count();
                $jobsBatch = Job::where('batch_no', $batchNo)->select('id')->cursor();
                $jobsBatch->chunk(100)->each(function ($chunk) use (&$jobs, $batchCount) {
                    $jobs[] = new ProcessEpgImportChunk($chunk->pluck('id')->toArray(), $batchCount);
                });

                // Last job in the batch
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
                            'channels' => 0, // not using...
                            'synced' => now(),
                            'errors' => $error,
                            'progress' => 100,
                            'processing' => false,
                        ]);
                        event(new SyncCompleted($epg));
                    })->dispatch();
            } else {
                // Log the exception
                logger()->error("Error processing \"{$this->epg->name}\"");

                // Send notification
                $error = "Invalid EPG file. Unable to read or download your EPG file. Please check the URL or uploaded file and try again.";
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
            ]);

            // Fire the epg synced event
            event(new SyncCompleted($this->epg));
        }
        return;
    }
}
