<?php

namespace App\Jobs;

use Throwable;
use Exception;
use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use Carbon\Carbon;
use M3uParser\M3uParser;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Cerbero\JsonParser\JsonParser;

class ProcessM3uImport implements ShouldQueue
{
    use Queueable;

    // To prevent errors when processing large files, limit imported channels to 50,000
    // NOTE: this only applies to M3U+ files
    //       Xtream API files are not limited
    public $maxItems = PHP_INT_MAX; // Default to no limit
    public $maxItemsHit = false;

    // Default user agent to use for HTTP requests
    // Used when user agent is not set in the playlist
    public $userAgent = 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.13) Gecko/20080311 Firefox/2.0.0.13';

    // Delete the job if the model is missing
    public $deleteWhenMissingModels = true;

    // Giving a timeout of 10 minutes to the Job to process the file
    public $timeout = 60 * 10;

    // Preprocess the playlist
    public bool $preprocess;

    // Use regex for group matching
    public bool $useRegex;

    // Selected groups for import
    public array $selectedGroups;

    // Included group prefixes for import
    public array $includedGroupPrefixes;

    // Available groups for the playlist
    public array $groups = [];

    /**
     * Create a new job instance.
     *
     * @param Playlist $playlist
     */
    public function __construct(
        public Playlist $playlist,
        public ?bool    $force = false,
    ) {
        $this->maxItems = config('dev.max_channels') + 1; // Maximum number of channels allowed for m3u import   
        $this->preprocess = $playlist->import_prefs['preprocess'] ?? false;
        $this->useRegex = $playlist->import_prefs['use_regex'] ?? false;
        $this->selectedGroups = $playlist->import_prefs['selected_groups'] ?? [];
        $this->includedGroupPrefixes = $playlist->import_prefs['included_group_prefixes'] ?? [];
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (!$this->force) {
            // Don't update if currently processing
            if ($this->playlist->processing) {
                return;
            }

            // Check if auto sync is enabled, or the playlist hasn't been synced yet
            if (!$this->playlist->auto_sync && $this->playlist->synced) {
                return;
            }
        }

        // Update the playlist status to processing
        $this->playlist->update([
            'processing' => true,
            'status' => Status::Processing,
            'errors' => null,
            'progress' => 0,
        ]);

        // Determine if using Xtream API or M3U+
        if ($this->playlist->xtream) {
            $this->processXtreamApi();
        } else {
            $this->processM3uPlus();
        }
    }

    /**
     * @param string $message
     * @param string $error
     * @return void
     */
    private function sendError($message, $error): void
    {
        // Log the exception
        logger()->error("Error processing \"{$this->playlist->name}\": $error");

        // Send notification
        Notification::make()
            ->danger()
            ->title("Error processing \"{$this->playlist->name}\"")
            ->body($message)
            ->broadcast($this->playlist->user);
        Notification::make()
            ->danger()
            ->title("Error processing \"{$this->playlist->name}\"")
            ->body($message)
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

    /**
     * Process the Xtream API
     */
    private function processXtreamApi()
    {
        // Flag job start time
        $start = now();

        // Surround in a try/catch block to catch any exceptions
        try {
            // Get the playlist
            $playlist = $this->playlist;

            // Get the playlist details
            $playlistId = $playlist->id;
            $userId = $playlist->user_id;
            $autoSort = $playlist->auto_sort;
            $batchNo = Str::orderedUuid()->toString();

            // Get the Xtream API credentials
            $baseUrl = str($playlist->xtream_config['url'])->replace(' ', '%20')->toString();
            $user = urlencode($playlist->xtream_config['username']);
            $password = $playlist->xtream_config['password'];
            $output = $playlist->xtream_config['output'] ?? 'ts';
            $categoriesToImport = $playlist->xtream_config['import_options'] ?? [];

            // Setup the category and stream URLs
            $userInfo = "$baseUrl/player_api.php?username=$user&password=$password";
            $liveCategories = "$baseUrl/player_api.php?username=$user&password=$password&action=get_live_categories";
            $liveStreams = "$baseUrl/player_api.php?username=$user&password=$password&action=get_live_streams";
            $vodCategories = "$baseUrl/player_api.php?username=$user&password=$password&action=get_vod_categories";
            $vodStreams = "$baseUrl/player_api.php?username=$user&password=$password&action=get_vod_streams";

            // Setup the user agent and SSL verification
            $verify = !$playlist->disable_ssl_verification;
            $userAgent = empty($playlist->user_agent) ? $this->userAgent : $playlist->user_agent;

            // Get the user info
            $userInfoResponse = Http::withUserAgent($userAgent)
                ->withOptions(['verify' => $verify])
                ->timeout(30)
                ->throw()->get($userInfo);
            if ($userInfoResponse->ok()) {
                $playlist->update([
                    'xtream_status' => $userInfoResponse->json(),
                ]);
            }

            // Set the initial progress
            $initialProgress = 3; // start at 3%

            // Get the live categories
            $categoriesResponse = Http::withUserAgent($userAgent)
                ->withOptions(['verify' => $verify])
                ->timeout(60 * 5) // set timeout to five minute
                ->throw()->get($liveCategories);
            if (!$categoriesResponse->ok()) {
                $error = $categoriesResponse->body();
                $message = "Error processing Live categories: $error";
                $this->sendError($message, $error);
                return;
            }

            // Get the live streams
            $liveStreamsResponse = Http::withUserAgent($userAgent)
                ->withOptions(['verify' => $verify])
                ->timeout(60 * 10) // set timeout to ten minute
                ->throw()->get($liveStreams);
            if (!$liveStreamsResponse->ok()) {
                $error = $liveStreamsResponse->body();
                $message = "Error processing Live streams: $error";
                $this->sendError($message, $error);
                return;
            }
            $initialProgress += 3;
            $playlist->update(['progress' => $initialProgress]);
            $categories = collect($categoriesResponse->json());

            // If including VOD, get the categories
            if (in_array('vod', $categoriesToImport)) {
                $vodCategoriesResponse = Http::withUserAgent($userAgent)
                    ->withOptions(['verify' => $verify])
                    ->timeout(60 * 5)
                    ->throw()->get($vodCategories);
                if (!$vodCategoriesResponse->ok()) {
                    $error = $vodCategoriesResponse->body();
                    $message = "Error processing VOD categories: $error";
                    $this->sendError($message, $error);
                    return;
                }

                // Get the VOD streams
                $vodStreamsResponse = Http::withUserAgent($userAgent)
                    ->withOptions(['verify' => $verify])
                    ->timeout(60 * 10) // set timeout to ten minute
                    ->throw()->get($vodStreams);
                if (!$vodStreamsResponse->ok()) {
                    $error = $vodStreamsResponse->body();
                    $message = "Error processing VOD streams: $error";
                    $this->sendError($message, $error);
                    return;
                }
                $initialProgress += 3;
                $playlist->update(['progress' => $initialProgress]);
                $vodCategories = collect($vodCategoriesResponse->json());
            }

            // Update progress
            $initialProgress += 5;
            $playlist->update(['progress' => $initialProgress]);

            // Update the groups array
            $groups = $categories->pluck('category_name');
            if (!is_string($vodCategories)) {
                $groups = $groups->merge($vodCategories->pluck('category_name'));
            }
            $this->groups = $groups->unique()->values()->toArray();

            // Setup common field values
            $channelFields = [
                'title' => null,
                'name' => '',
                'url' => null,
                'logo' => null,
                'channel' => null,
                'group' => '',
                'group_internal' => '',
                'stream_id' => null,
                'lang' => null,
                'country' => null,
                'playlist_id' => $playlistId,
                'user_id' => $userId,
                'import_batch_no' => $batchNo,
                'enabled' => $playlist->enable_channels,
            ];

            // Update progress
            $playlist->update(['progress' => 10]);

            // Keep track of channel number
            $channelNo = 0;
            if ($autoSort) {
                $channelFields['sort'] = 0;
            }

            // Get the live streams
            $liveStreams = JsonParser::parse($liveStreamsResponse->body());
            $vodStreams = isset($vodStreamsResponse) ? JsonParser::parse($vodStreamsResponse->body()) : null;

            // Process the live streams
            $streamBaseUrl = "$baseUrl/live/$user/$password";
            $vodBaseUrl = "$baseUrl/movie/$user/$password";
            $collection = LazyCollection::make(function () use (
                $liveStreams,
                $vodStreams,
                $streamBaseUrl,
                $vodBaseUrl,
                $categories,
                $vodCategories,
                $channelFields,
                $autoSort,
                $channelNo,
                $output
            ) {
                // Output the live streams first
                foreach ($liveStreams as $item) {
                    // Increment channel number
                    ++$channelNo;

                    // Get the category
                    $category = $categories->firstWhere('category_id', $item['category_id']);

                    // Determine if the channel should be included
                    if ($this->preprocess && !$this->shouldIncludeChannel($category['category_name'] ?? '')) {
                        continue;
                    }
                    $channel = [
                        ...$channelFields,
                        'title' => $item['name'],
                        'name' => $item['name'],
                        'url' => "$streamBaseUrl/{$item['stream_id']}.$output",
                        'logo' => $item['stream_icon'],
                        'group' => $category['category_name'] ?? '',
                        'group_internal' => $category['category_name'] ?? '',
                        'stream_id' => $item['epg_channel_id'] ?? $item['stream_id'], // prefer EPG id for mapping, if set
                        'channel' => $item['num'] ?? null,
                    ];
                    if ($autoSort) {
                        $channel['sort'] = $channelNo;
                    }
                    yield $channel;
                }

                // If VOD streams, add them
                if ($vodStreams) {
                    foreach ($vodStreams as $item) {
                        // Increment channel number
                        ++$channelNo;

                        // Get the category
                        $category = $vodCategories->firstWhere('category_id', $item['category_id']);

                        // Determine if the channel should be included
                        if ($this->preprocess && !$this->shouldIncludeChannel($category['category_name'] ?? '')) {
                            continue;
                        }
                        $extension = $item['container_extension'] ?? "mp4";
                        $channel = [
                            ...$channelFields,
                            'title' => $item['name'],
                            'name' => $item['name'],
                            'url' => "$vodBaseUrl/{$item['stream_id']}." . $extension,
                            'logo' => $item['stream_icon'],
                            'group' => $category['category_name'] ?? '',
                            'group_internal' => $category['category_name'] ?? '',
                            'stream_id' => $item['stream_id'],
                            'channel' => $item['num'] ?? null,
                        ];
                        if ($autoSort) {
                            $channel['sort'] = $channelNo;
                        }
                        yield $channel;
                    }
                }
            });
            $this->processChannelCollection($collection, $playlist, $batchNo, $userId, $start);
        } catch (Exception $e) {
            // Log the exception
            logger()->error("Error processing \"{$this->playlist->name}\": {$e->getMessage()}");

            // Send notification
            Notification::make()
                ->danger()
                ->title("Error processing \"{$this->playlist->name}\"")
                ->body('Please view your notifications for details.')
                ->broadcast($this->playlist->user);
            Notification::make()
                ->danger()
                ->title("Error processing \"{$this->playlist->name}\"")
                ->body($e->getMessage())
                ->sendToDatabase($this->playlist->user);

            // Update the playlist
            $this->playlist->update([
                'status' => Status::Failed,
                'synced' => now(),
                'errors' => $e->getMessage(),
                'progress' => 100,
                'processing' => false,
            ]);

            // Fire the playlist synced event
            event(new SyncCompleted($this->playlist));
        }
        return;
    }

    /**
     * Process the M3U+ playlist
     */
    private function processM3uPlus()
    {
        // Flag job start time
        $start = now();

        // Surround in a try/catch block to catch any exceptions
        try {
            // Get the playlist
            $playlist = $this->playlist;

            // Get the playlist details
            $playlistId = $playlist->id;
            $userId = $playlist->user_id;
            $autoSort = $playlist->auto_sort;
            $batchNo = Str::orderedUuid()->toString();

            $filePath = null;
            if ($playlist->url && str_starts_with($playlist->url, 'http')) {
                // Normalize the playlist url and get the filename
                $url = str($playlist->url)->replace(' ', '%20');

                // We need to grab the file contents first and set to temp file
                $verify = !$playlist->disable_ssl_verification;
                $userAgent = empty($playlist->user_agent) ? $this->userAgent : $playlist->user_agent;
                $response = Http::withUserAgent($userAgent)
                    ->withOptions(['verify' => $verify])
                    ->timeout(60 * 5) // set timeout to five minues
                    ->throw()->get($url->toString());

                if ($response->ok()) {
                    // Remove previous saved files
                    Storage::disk('local')->deleteDirectory($playlist->folder_path);

                    // Save the file to local storage
                    Storage::disk('local')->put(
                        $playlist->file_path,
                        $response->body()
                    );

                    // Update the file path
                    $filePath = Storage::disk('local')->path($playlist->file_path);
                }
            } else {
                // Get uploaded file contents
                if ($playlist->uploads && Storage::disk('local')->exists($playlist->uploads)) {
                    // Get the contents and the path
                    $filePath = Storage::disk('local')->path($playlist->uploads);
                } else if ($playlist->url) {
                    $filePath = $playlist->url;
                }
            }

            // Update progress
            $playlist->update(['progress' => 5]); // set to 5% to start

            // If file path is set, we can process the file
            if ($filePath) {
                // Update progress
                $playlist->update(['progress' => 10]);

                // Setup common field values
                $channelFields = [
                    'title' => null,
                    'name' => '',
                    'url' => null,
                    'logo' => null,
                    'channel' => null,
                    'group' => '',
                    'group_internal' => '',
                    'stream_id' => null,
                    'lang' => null,
                    'country' => null,
                    'playlist_id' => $playlistId,
                    'user_id' => $userId,
                    'import_batch_no' => $batchNo,
                    'enabled' => $playlist->enable_channels,
                    'extvlcopt' => null,
                    'kodidrop' => null,
                    'shift' => 0
                ];
                if ($autoSort) {
                    $channelFields['sort'] = 0;
                }

                // Extract the channels and groups from the m3u
                $excludeFileTypes = $playlist->import_prefs['ignored_file_types'] ?? [];
                $collection = LazyCollection::make(function () use (
                    $filePath,
                    $channelFields,
                    $excludeFileTypes,
                    $autoSort,
                ) {
                    // Keep track of channel number
                    $channelNo = 0;

                    // Setup the attribute -> key mapping
                    $attributes = [
                        'name' => 'tvg-name',
                        'stream_id' => 'tvg-id',
                        'logo' => 'tvg-logo',
                        'group' => 'group-title',
                        'group_internal' => 'group-title',
                        'channel' => 'tvg-chno',
                        'lang' => 'tvg-language',
                        'country' => 'tvg-country',
                        'shift' => 'tvg-shift', // deprecated, use 'timeshift' instead
                        'shift' => 'timeshift' // timeshift in hours, falls back to 'tvg-shift' if not set
                    ];

                    // Parse the M3U file
                    // NOTE: max line length is set to 2048 to prevent memory issues
                    $m3uParser = new M3uParser();
                    $m3uParser->addDefaultTags();
                    $count = 0;
                    foreach ($m3uParser->parseFile($filePath, max_length: 2048) as $item) {
                        // Increment channel number
                        ++$channelNo;

                        $url = $item->getPath();
                        foreach ($excludeFileTypes as $excludeFileType) {
                            if (str_ends_with($url, $excludeFileType)) {
                                continue 2;
                            }
                        }
                        $channel = [
                            ...$channelFields,
                            'url' => $url,
                        ];
                        $extvlcopt = [];
                        $kodidrop = [];
                        foreach ($item->getExtTags() as $extTag) {
                            if ($extTag instanceof \M3uParser\Tag\ExtInf) {
                                $channel['title'] = $extTag->getTitle();
                                foreach ($attributes as $key => $attribute) {
                                    if ($extTag->hasAttribute($attribute)) {
                                        if ($attribute === 'tvg-chno') {
                                            $channel[$key] = (int)$extTag->getAttribute($attribute);
                                        } else {
                                            $channel[$key] = str_replace(
                                                [',', '"', "'"],
                                                '',
                                                trim($extTag->getAttribute($attribute))
                                            );
                                        }
                                    }
                                }
                            }
                            if ($extTag instanceof \M3uParser\Tag\ExtVlcOpt) {
                                $extvlcopt[] = [
                                    'key' => $extTag->getKey(),
                                    'value' => $extTag->getValue(),
                                ];
                            }
                            if ($extTag instanceof \M3uParser\Tag\KodiDrop) {
                                $kodidrop[] = [
                                    'key' => $extTag->getKey(),
                                    'value' => $extTag->getValue(),
                                ];
                            }
                        }
                        if (count($extvlcopt) > 0) {
                            $channel['extvlcopt'] = json_encode($extvlcopt);
                        }
                        if (count($kodidrop) > 0) {
                            $channel['kodidrop'] = json_encode($kodidrop);
                        }
                        if (!isset($channel['title'])) {
                            // Name is required, fallback to stream ID if available, otherwise set to title
                            // Channel will be skipped on import of not set to something...
                            $channel['title'] = $channel['stream_id'] ?? $channel['name'];
                        }

                        // Get the channel group and determine if the channel should be included
                        $channelGroup = explode(';', $channel['group']);
                        if (count($channelGroup) > 0) {
                            foreach ($channelGroup as $chGroup) {
                                // Add group to list
                                $this->groups[] = $chGroup;

                                // Check if preprocessing, and should include group
                                if ($this->preprocess && !$this->shouldIncludeChannel($chGroup)) {
                                    continue;
                                }

                                // Check if max channels reached
                                if ($count++ >= $this->maxItems) {
                                    $this->maxItemsHit = true;
                                    continue;
                                }

                                // Update group name to the singular name and return the channel
                                $channel['group'] = $chGroup;
                                $channel['group_internal'] = $chGroup;

                                // Set channel number, if auto sort is enabled
                                if ($autoSort) {
                                    $channel['sort'] = $channelNo;
                                }

                                // Return the channel
                                yield $channel;
                            }
                        } else {
                            // Add group to list
                            $this->groups[] = $channel['group'];

                            // Check if preprocessing, and should include group
                            if ($this->preprocess && !$this->shouldIncludeChannel($channel['group'])) {
                                continue;
                            }

                            // Check if max channels reached
                            if ($count++ >= $this->maxItems) {
                                $this->maxItemsHit = true;
                                continue;
                            }

                            // Set channel number, if auto sort is enabled
                            if ($autoSort) {
                                $channel['sort'] = $channelNo;
                            }

                            // Return the channel   
                            yield $channel;
                        }
                    }
                });
                $this->processChannelCollection($collection, $playlist, $batchNo, $userId, $start);
            } else {
                // Log the exception
                logger()->error("Error processing \"{$playlist->name}\"");

                // Send notification
                $error = "Invalid playlist file. Unable to read or download your playlist file. Please check the URL or uploaded file and try again.";
                Notification::make()
                    ->danger()
                    ->title("Error processing \"{$playlist->name}\"")
                    ->body('Please view your notifications for details.')
                    ->broadcast($playlist->user);
                Notification::make()
                    ->danger()
                    ->title("Error processing \"{$playlist->name}\"")
                    ->body($error)
                    ->sendToDatabase($playlist->user);

                // Update the Playlist
                $playlist->update([
                    'status' => Status::Failed,
                    'channels' => 0, // not using...
                    'synced' => now(),
                    'errors' => $error,
                    'progress' => 100,
                    'processing' => false,
                ]);

                // Fire the playlist synced event
                event(new SyncCompleted($this->playlist));
                return;
            }
        } catch (\Exception $e) {
            // Log the exception
            logger()->error("Error processing \"{$this->playlist->name}\": {$e->getMessage()}");

            // Send notification
            Notification::make()
                ->danger()
                ->title("Error processing \"{$this->playlist->name}\"")
                ->body('Please view your notifications for details.')
                ->broadcast($this->playlist->user);
            Notification::make()
                ->danger()
                ->title("Error processing \"{$this->playlist->name}\"")
                ->body($e->getMessage())
                ->sendToDatabase($this->playlist->user);

            // Update the playlist
            $this->playlist->update([
                'status' => Status::Failed,
                'synced' => now(),
                'errors' => $e->getMessage(),
                'progress' => 100,
                'processing' => false,
            ]);

            // Fire the playlist synced event
            event(new SyncCompleted($this->playlist));
        }
        return;
    }

    /**
     * Process the channel collection
     */
    private function processChannelCollection(
        LazyCollection $collection,
        Playlist       $playlist,
        string         $batchNo,
        int            $userId,
        Carbon         $start
    ) {
        // Get the playlist ID
        $playlistId = $playlist->id;

        // Process the collection
        $collection->groupBy('group')->chunk(10)->each(function (LazyCollection $grouped) use ($userId, $playlistId, $batchNo) {
            $grouped->each(function ($channels, $groupName) use ($userId, $playlistId, $batchNo) {
                // Add group and associated channels
                $group = Group::where([
                    'name_internal' => $groupName ?? '',
                    'playlist_id' => $playlistId,
                    'user_id' => $userId,
                    'custom' => false,
                ])->first();
                if (!$group) {
                    $group = Group::create([
                        'name' => $groupName ?? '',
                        'name_internal' => $groupName ?? '',
                        'playlist_id' => $playlistId,
                        'user_id' => $userId,
                        'import_batch_no' => $batchNo,
                    ]);
                } else {
                    $group->update([
                        'import_batch_no' => $batchNo,
                    ]);
                }
                $channels->chunk(50)->each(function ($chunk) use ($playlistId, $batchNo, $group) {
                    Job::create([
                        'title' => "Processing channel import for group: {$group->name}",
                        'batch_no' => $batchNo,
                        'payload' => $chunk->toArray(),
                        'variables' => [
                            'groupId' => $group->id,
                            'groupName' => $group->name,
                            'playlistId' => $playlistId,
                        ]
                    ]);
                });
            });
        });

        // Remove duplicate groups
        $groups = array_values(array_unique($this->groups));

        // Update progress
        $playlist->update(['progress' => 15]);

        // Check if preprocessing, and not groups selected yet
        if (
            $this->preprocess
            && count($this->selectedGroups) === 0
            && count($this->includedGroupPrefixes) === 0
        ) {
            $completedIn = $start->diffInSeconds(now());
            $completedInRounded = round($completedIn, 2);
            $playlist->update([
                'status' => Status::Completed,
                'channels' => 0, // not using...
                'synced' => now(),
                'errors' => null,
                'sync_time' => $completedIn,
                'progress' => 100,
                'processing' => false,
                'groups' => $groups,
            ]);

            // Send notification
            $message = "\"{$playlist->name}\" has been preprocessed successfully. You can now select the groups you would like to import and process the playlist again to import your selected groups. Preprocessing completed in {$completedInRounded} seconds.";
            Notification::make()
                ->success()
                ->title('Playlist Preprocessing Completed')
                ->body($message)
                ->broadcast($playlist->user);
            Notification::make()
                ->success()
                ->title('Playlist Preprocessing Completed')
                ->body($message)
                ->sendToDatabase($playlist->user);
        } else {
            // Get the jobs for the batch
            $jobs = [];
            $batchCount = Job::where('batch_no', $batchNo)->count();
            $jobsBatch = Job::where('batch_no', $batchNo)->select('id')->cursor();
            $jobsBatch->chunk(100)->each(function ($chunk) use (&$jobs, $batchCount) {
                $jobs[] = new ProcessM3uImportChunk($chunk->pluck('id')->toArray(), $batchCount);
            });

            // Last job in the batch
            $jobs[] = new ProcessM3uImportComplete($userId, $playlistId, $groups, $batchNo, $start, $this->maxItemsHit);
            Bus::chain($jobs)
                ->onConnection('redis') // force to use redis connection
                ->onQueue('import')
                ->catch(function (Throwable $e) use ($playlist) {
                    $error = "Error processing \"{$playlist->name}\": {$e->getMessage()}";
                    Notification::make()
                        ->danger()
                        ->title("Error processing \"{$playlist->name}\"")
                        ->body('Please view your notifications for details.')
                        ->broadcast($playlist->user);
                    Notification::make()
                        ->danger()
                        ->title("Error processing \"{$playlist->name}\"")
                        ->body($error)
                        ->sendToDatabase($playlist->user);
                    $playlist->update([
                        'status' => Status::Failed,
                        'channels' => 0, // not using...
                        'synced' => now(),
                        'errors' => $error,
                        'progress' => 100,
                        'processing' => false,
                    ]);
                    event(new SyncCompleted($playlist));
                })->dispatch();
        }
    }

    /**
     * Determine if the channel should be included
     *
     * @param string $groupName
     */
    private function shouldIncludeChannel($groupName): bool
    {
        // Check if group is selected...
        if (in_array(
            $groupName,
            $this->selectedGroups
        )) {
            // Group selected directly
            return true;
        } else {
            // ...if group not selected, check if group starts with any of the included prefixes
            // (only check if the group isn't directly included already)
            foreach ($this->includedGroupPrefixes as $pattern) {
                if ($this->useRegex) {
                    // Escape existing delimiters in user input
                    $delimiter = '/';
                    $escapedPattern = str_replace($delimiter, '\\' . $delimiter, $pattern);
                    $finalPattern = $delimiter . $escapedPattern . $delimiter . 'u';
                    if (preg_match($finalPattern, $groupName)) {
                        return true;
                    }
                } else {
                    // Use simple string prefix matching
                    if (str_starts_with($groupName, $pattern)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
