<?php

namespace App\Jobs;

use M3uParser\Tag\ExtInf;
use M3uParser\Tag\ExtVlcOpt;
use M3uParser\Tag\KodiDrop;
use Throwable;
use Exception;
use App\Enums\Status;
use App\Events\SyncCompleted;
use App\Models\Category;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\SourceCategory;
use App\Models\SourceGroup;
use App\Traits\ProviderRequestDelay;
use Carbon\Carbon;
use M3uParser\M3uParser;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Facades\Log;
use JsonMachine\Items;
use M3uParser\Tag\ExtGrp;

class ProcessM3uImport implements ShouldQueue
{
    use Queueable;
    use ProviderRequestDelay;

    // Don't retry the job on failure
    public $tries = 1;

    // To prevent errors when processing large files, limit imported channels to 50,000
    // NOTE: this only applies to M3U+ files
    //       Xtream API files are not limited
    public $maxItems = PHP_INT_MAX; // Default to no limit
    public $maxItemsHit = false;

    // Default user agent to use for HTTP requests
    // Used when user agent is not set in the playlist
    public $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36';

    // Delete the job if the model is missing
    public $deleteWhenMissingModels = true;

    // Giving a timeout of 60 minutes to the Job to process the file
    public $timeout = 60 * 60;

    // Preprocess the playlist
    public bool $preprocess;

    // Use regex for group matching
    public bool $useRegex;

    // Selected groups for import
    public array $selectedGroups;

    // Included group prefixes for import
    public array $includedGroupPrefixes;

    // Selected groups for import
    public array $selectedVodGroups;

    // Included group prefixes for import
    public array $includedVodGroupPrefixes;

    // Available groups for the playlist
    public array $groups = [];

    // Selected categories for import
    public array $selectedCategories;

    // Included category prefixes for import
    public array $includedCategoryPrefixes;

    // Groups we should auto-enable channels for
    public Collection $enabledGroups;

    // Categories we should auto-enable series for
    public Collection $enabledCategories;

    // M3U Parser instance
    public $m3uParser = null;

    /**
     * Create a new job instance.
     *
     * @param Playlist $playlist
     */
    public function __construct(
        public Playlist $playlist,
        public ?bool    $force = false,
        public ?bool    $isNew = false,
    ) {
        // General processing settings
        $this->maxItems = config('dev.max_channels') + 1; // Maximum number of channels allowed for m3u import   
        $this->preprocess = $playlist->import_prefs['preprocess'] ?? false;
        $this->useRegex = $playlist->import_prefs['use_regex'] ?? false;

        // Selected live groups for import
        $this->selectedGroups = $playlist->import_prefs['selected_groups'] ?? [];
        $this->includedGroupPrefixes = $playlist->import_prefs['included_group_prefixes'] ?? [];

        // Selected VOD groups for import
        $this->selectedVodGroups = $playlist->import_prefs['selected_vod_groups'] ?? [];
        $this->includedVodGroupPrefixes = $playlist->import_prefs['included_vod_group_prefixes'] ?? [];

        // Selected categories for import
        $this->selectedCategories = $playlist->import_prefs['selected_categories'] ?? [];
        $this->includedCategoryPrefixes = $playlist->import_prefs['included_category_prefixes'] ?? [];

        // Get the enabled groups and categories for this playlist
        $this->enabledGroups = $playlist->groups()->where('enabled', true)->get('name')->pluck('name');
        $this->enabledCategories = $playlist->categories()->where('enabled', true)->get('name')->pluck('name');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if (!$this->force) {
            // Don't update if currently processing
            if ($this->playlist->isProcessing()) {
                return;
            }

            // Check if auto sync is enabled, or the playlist hasn't been synced yet
            if (!$this->playlist->auto_sync && $this->playlist->synced) {
                return;
            }
        }

        // Update the playlist status to processing
        $this->playlist->update([
            'status' => Status::Processing,
            'synced' => now(),
            'errors' => null,
            'progress' => 0,
            'vod_progress' => 0,
            'series_progress' => 0,
            'processing' => [
                ...$this->playlist->processing ?? [],
                'live_processing' => false,
                'vod_processing' => false,
                'series_processing' => false,
            ]
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
            'progress' => 0,
            'vod_progress' => 0,
            'series_progress' => 0,
            'processing' => [
                ...$this->playlist->processing ?? [],
                'live_processing' => false,
                'vod_processing' => false,
                'series_processing' => false,
            ]
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
            $user = $playlist->xtream_config['username'];
            $password = $playlist->xtream_config['password'];
            $output = $playlist->xtream_config['output'] ?? 'ts';
            $categoriesToImport = $playlist->xtream_config['import_options'] ?? [];

            // Setup the category and stream URLs
            $userInfo = "$baseUrl/player_api.php?username=$user&password=$password";
            $liveCategories = "$baseUrl/player_api.php?username=$user&password=$password&action=get_live_categories";
            $liveStreamsUrl = "$baseUrl/player_api.php?username=$user&password=$password&action=get_live_streams";
            $vodCategories = "$baseUrl/player_api.php?username=$user&password=$password&action=get_vod_categories";
            $vodStreamsUrl = "$baseUrl/player_api.php?username=$user&password=$password&action=get_vod_streams";
            $seriesCategories = "$baseUrl/player_api.php?username=$user&password=$password&action=get_series_categories";

            // Determine which imports are enabled
            $liveStreamsEnabled = in_array('live', $categoriesToImport);
            $vodStreamsEnabled = in_array('vod', $categoriesToImport);
            $seriesStreamsEnabled = in_array('series', $categoriesToImport);

            // Setup the user agent and SSL verification
            $verify = !$playlist->disable_ssl_verification;
            $userAgent = empty($playlist->user_agent) ? $this->userAgent : $playlist->user_agent;

            // Get the user info with provider throttling
            $userInfoResponse = $this->withProviderThrottling(fn() => Http::withUserAgent($userAgent)
                ->withOptions(['verify' => $verify])
                ->timeout(30)
                ->throw()->get($userInfo));
            if ($userInfoResponse->ok()) {
                $playlist->update([
                    'xtream_status' => $userInfoResponse->json(),
                ]);
            }

            // If including Live streams, get the categories and streams
            if ($liveStreamsEnabled) {
                $categoriesResponse = $this->withProviderThrottling(fn() => Http::withUserAgent($userAgent)
                    ->withOptions(['verify' => $verify])
                    ->timeout(60) // set timeout to one minute
                    ->throw()->get($liveCategories));
                if (!$categoriesResponse->ok()) {
                    $error = $categoriesResponse->body();
                    $message = "Error processing Live categories: $error";
                    $this->sendError($message, $error);
                    return;
                }
                $liveCategories = collect($categoriesResponse->json());

                // Get the live streams and save to a file
                $liveFp = Storage::disk('local')->path("{$playlist->folder_path}/live_streams.json");

                // Make sure the folder exists
                Storage::disk('local')->makeDirectory($playlist->folder_path, 0755, true);

                // Delete the file if it already exists so we can start fresh
                if (Storage::disk('local')->exists($liveFp)) {
                    Storage::disk('local')->delete($liveFp);
                }
                $liveResponse = $this->withProviderThrottling(fn() => Http::withUserAgent($userAgent)
                    ->sink($liveFp) // Save the response to a file for later processing
                    ->withOptions(['verify' => $verify])
                    ->timeout(60 * 5)
                    ->throw()->get($liveStreamsUrl));
                if (!$liveResponse->ok()) {
                    $error = $liveResponse->body();
                    $message = "Error processing Live streams: $error";
                    $this->sendError($message, $error);
                    return;
                }
                $playlist->update(attributes: ['progress' => 5]);
            }

            // If including VOD, get the categories and streams
            if ($vodStreamsEnabled) {
                $vodCategoriesResponse = $this->withProviderThrottling(fn() => Http::withUserAgent($userAgent)
                    ->withOptions(['verify' => $verify])
                    ->timeout(60) // set timeout to one minute
                    ->throw()->get($vodCategories));
                if (!$vodCategoriesResponse->ok()) {
                    $error = $vodCategoriesResponse->body();
                    $message = "Error processing VOD categories: $error";
                    $this->sendError($message, $error);
                    return;
                }
                $vodCategories = collect($vodCategoriesResponse->json());

                // Get the VOD streams and save to a file
                $vodFp = Storage::disk('local')->path("{$playlist->folder_path}/vod_streams.json");

                // Make sure the folder exists
                Storage::disk('local')->makeDirectory($playlist->folder_path, 0755, true);

                // Delete the file if it already exists so we can start fresh
                if (Storage::disk('local')->exists($vodFp)) {
                    Storage::disk('local')->delete($vodFp);
                }
                $vodResponse = $this->withProviderThrottling(fn() => Http::withUserAgent($userAgent)
                    ->sink($vodFp) // Save the response to a file for later processing
                    ->withOptions(['verify' => $verify])
                    ->timeout(60 * 5)
                    ->throw()->get($vodStreamsUrl));
                if (!$vodResponse->ok()) {
                    $error = $vodResponse->body();
                    $message = "Error processing VOD streams: $error";
                    $this->sendError($message, $error);
                    return;
                }
                $playlist->update(attributes: ['vod_progress' => 5]);
            }

            // If including Series streams, get the categories and streams
            if ($seriesStreamsEnabled) {
                $seriesCategoriesResponse = $this->withProviderThrottling(fn() => Http::withUserAgent($userAgent)
                    ->withOptions(['verify' => $verify])
                    ->timeout(60) // set timeout to one minute
                    ->throw()->get($seriesCategories));
                if (!$seriesCategoriesResponse->ok()) {
                    $error = $seriesCategoriesResponse->body();
                    $message = "Error processing Series categories: $error";
                    $this->sendError($message, $error);
                    return;
                }
                $seriesCategories = collect($seriesCategoriesResponse->json());
            } else {
                $seriesCategories = null;
            }

            // Get the groups
            $liveGroups = $liveStreamsEnabled && !is_string($liveCategories)
                ? $liveCategories
                : collect([]);
            $vodGroups = $vodStreamsEnabled && !is_string($vodCategories)
                ? $vodCategories
                : collect([]);

            // Setup common field values
            $channelFields = [
                'title' => null,
                'name' => '',
                'url' => null,
                'logo' => null,
                'logo_internal' => null, // internal logo path
                'channel' => null,
                'group' => '',
                'group_internal' => '',
                'stream_id' => null,
                'lang' => null,
                'country' => null,
                'playlist_id' => $playlistId,
                'user_id' => $userId,
                'import_batch_no' => $batchNo,
                'new' => true,
                'enabled' => $playlist->enable_channels,
                'catchup' => null,
                'catchup_source' => null,
                'shift' => 0,
                'tvg_shift' => null,
                'is_vod' => false, // default false
                'container_extension' => null, // default null, will be set for VOD streams
                'year' => null, // new field for year
                'rating' => null, // new field for rating
                'rating_5based' => null, // new field for 5-based rating
                'source_id' => null, // source ID for the channel
            ];

            // Keep track of channel number
            $channelNo = 0;
            if ($autoSort) {
                $channelFields['sort'] = 0;
            }

            // Get the live streams
            $liveStreams = $liveStreamsEnabled && $liveFp ? Items::fromFile($liveFp) : null;
            $vodStreams = $vodStreamsEnabled && $vodFp ? Items::fromFile($vodFp) : null;

            // Process the live streams
            $streamBaseUrl = "$baseUrl/live/$user/$password";
            $vodBaseUrl = "$baseUrl/movie/$user/$password";

            // Create separate collections for live and VOD streams
            $liveCollection = null;
            $vodCollection = null;

            // Live streams collection
            if ($liveStreamsEnabled && $liveStreams) {
                $liveCollection = LazyCollection::make(function () use (
                    $liveStreams,
                    $streamBaseUrl,
                    $liveCategories,
                    $channelFields,
                    $autoSort,
                    $channelNo,
                    $output
                ) {
                    $localChannelNo = $channelNo;
                    foreach ($liveStreams as $item) {
                        // Increment channel number
                        ++$localChannelNo;

                        // Get the category
                        $category = $liveCategories->firstWhere('category_id', $item->category_id);

                        // Determine if the channel should be included
                        if ($this->preprocess && !$this->shouldIncludeChannel($category['category_name'] ?? '')) {
                            continue;
                        }
                        $channel = [
                            ...$channelFields,
                            'title' => $item->name,
                            'name' => $item->name,
                            'url' => "$streamBaseUrl/{$item->stream_id}.$output",
                            'logo_internal' => Str::replace(' ', '%20', $item->stream_icon ?? ''), // internal logo path
                            'group' => $category['category_name'] ?? '',
                            'group_internal' => $category['category_name'] ?? '',
                            'stream_id' => $item->epg_channel_id ?? $item->stream_id, // prefer EPG id for mapping, if set
                            'source_id' => $item->stream_id, // source ID for the channel
                            'channel' => $item->num ?? null,
                            'catchup' => $item->tv_archive ?? null,
                            'shift' => $item->tv_archive_duration ?? 0,
                            // 'tvg_shift' => $item->tvg_shift ?? null, // @TODO: check if this is on Xtream API, not seeing it as a deffinition in the API docs
                        ];
                        if ($autoSort) {
                            $channel['sort'] = $localChannelNo;
                        }
                        if ($this->enabledGroups->contains($category['category_name'] ?? '')) {
                            $channel['enabled'] = true;
                        }
                        yield $channel;
                    }
                    $this->playlist->update(['progress' => 10]);
                });
            }

            // VOD streams collection
            if ($vodStreamsEnabled && $vodStreams) {
                $vodCollection = LazyCollection::make(function () use (
                    $vodStreams,
                    $vodBaseUrl,
                    $vodCategories,
                    $channelFields,
                    $autoSort,
                    $channelNo
                ) {
                    $localChannelNo = $channelNo;
                    foreach ($vodStreams as $item) {
                        // Increment channel number
                        ++$localChannelNo;

                        // Get the category
                        $category = $vodCategories->firstWhere('category_id', $item->category_id);

                        // Determine if the channel should be included
                        if ($this->preprocess && !$this->shouldIncludeVod($category['category_name'] ?? '')) {
                            continue;
                        }
                        $extension = $item->container_extension ?? "mp4";
                        $channel = [
                            ...$channelFields,
                            'title' => $item->name,
                            'name' => $item->name,
                            'url' => "$vodBaseUrl/{$item->stream_id}." . $extension,
                            'logo_internal' => Str::replace(' ', '%20', $item->stream_icon ?? ''), // internal logo path
                            'group' => $category['category_name'] ?? '',
                            'group_internal' => $category['category_name'] ?? '',
                            'stream_id' => $item->stream_id,
                            'source_id' => $item->stream_id, // source ID for the channel
                            'channel' => $item->num ?? null,
                            'is_vod' => true, // mark as VOD
                            'container_extension' => $extension, // store the container extension
                            'year' => $item->year ?? null, // new field for year
                            'rating' => $item->rating ?? null, // new field for rating
                            'rating_5based' => $item->rating_5based ?? null, // new field for 5-based rating
                        ];
                        if ($autoSort) {
                            $channel['sort'] = $localChannelNo;
                        }
                        if ($this->enabledGroups->contains($category['category_name'] ?? '')) {
                            $channel['enabled'] = true;
                        }
                        yield $channel;
                    }
                    $this->playlist->update(['vod_progress' => 10]);
                });
            }

            $this->processXtreamChannelCollections(
                liveCollection: $liveCollection,
                vodCollection: $vodCollection,
                playlist: $playlist,
                batchNo: $batchNo,
                userId: $userId,
                start: $start,
                seriesCategories: $seriesCategories,
                liveStreamsEnabled: $liveStreamsEnabled,
                vodStreamsEnabled: $vodStreamsEnabled,
                liveGroups: $liveGroups,
                vodGroups: $vodGroups,
            );
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
                'progress' => 0,
                'vod_progress' => 0,
                'processing' => [
                    ...$this->playlist->processing ?? [],
                    'live_processing' => false,
                    'vod_processing' => false,
                ]
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
                $response = $this->withProviderThrottling(fn() => Http::withUserAgent($userAgent)
                    ->withOptions(['verify' => $verify])
                    ->timeout(60 * 5) // set timeout to five minues
                    ->throw()->get($url->toString()));

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
                    'logo_internal' => null,
                    'channel' => null,
                    'group' => '',
                    'group_internal' => '',
                    'stream_id' => null,
                    'station_id' => null,
                    'lang' => null,
                    'country' => null,
                    'playlist_id' => $playlistId,
                    'user_id' => $userId,
                    'import_batch_no' => $batchNo,
                    'new' => true,
                    'enabled' => $playlist->enable_channels,
                    'extvlcopt' => null,
                    'kodidrop' => null,
                    'catchup' => null,
                    'catchup_source' => null,
                    'shift' => 0,
                    'tvg_shift' => null,
                    'source_id' => null, // source ID for the channel
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
                        'station_id' => 'tvc-guide-stationid', // import Gracenote station ID if available
                        'logo_internal' => 'tvg-logo',
                        'group' => 'group-title',
                        'group_internal' => 'group-title',
                        'channel' => 'tvg-chno',
                        'lang' => 'tvg-language',
                        'country' => 'tvg-country',
                        'shift' => 'tvg-shift', // deprecated, use 'timeshift' instead
                        'shift' => 'timeshift', // timeshift in hours, falls back to 'tvg-shift' if not set
                        'catchup' => 'catchup',
                        'catchup_source' => 'catchup-source',
                        'tvg_shift' => 'tvg-shift', // used for EPG shift in hrs (can be negative)
                    ];

                    // Parse the M3U file
                    // NOTE: max line length is set to 2048 to prevent memory issues
                    $this->m3uParser = new M3uParser();
                    $this->m3uParser->addDefaultTags();
                    $count = 0;
                    foreach ($this->m3uParser->parseFile($filePath, max_length: 2048) as $item) {
                        // Increment channel number
                        ++$channelNo;

                        $url = $item->getPath();
                        if (is_string($url)) {
                            if (str_starts_with($url, 'http//')) {
                                $url = 'http://' . substr($url, strlen('http//'));
                            } elseif (str_starts_with($url, 'https//')) {
                                $url = 'https://' . substr($url, strlen('https//'));
                            }
                        }
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
                            if ($extTag instanceof ExtGrp) {
                                // Set group, will be overridden by ExtInf `group-title` attribute, if present
                                $channel['group'] = $extTag->getValue();
                                $channel['group_internal'] = $extTag->getValue();
                            }
                            if ($extTag instanceof ExtInf) {
                                $channel['title'] = $extTag->getTitle();
                                foreach ($attributes as $key => $attribute) {
                                    if ($extTag->hasAttribute($attribute)) {
                                        if ($attribute === 'tvg-chno') {
                                            $channel[$key] = (int)$extTag->getAttribute($attribute);
                                        } elseif ($attribute === 'tvg-logo') {
                                            $channel[$key] = Str::replace(' ', '%20', trim($extTag->getAttribute($attribute)));
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
                            if ($extTag instanceof ExtVlcOpt) {
                                $extvlcopt[] = [
                                    'key' => $extTag->getKey(),
                                    'value' => $extTag->getValue(),
                                ];
                            }
                            if ($extTag instanceof KodiDrop) {
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

                                // Set the source ID based on our composite index
                                $channel['source_id'] = md5($channel['title'] . $channel['name'] . $chGroup);

                                // Update group name to the singular name and return the channel
                                $channel['group'] = $chGroup;
                                $channel['group_internal'] = $chGroup;

                                // Set channel number, if auto sort is enabled
                                if ($autoSort) {
                                    $channel['sort'] = $channelNo;
                                }

                                // Auto-enable if in enabled group
                                if ($this->enabledGroups->contains($channel['group'] ?? '')) {
                                    $channel['enabled'] = true;
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

                            // Set the source ID based on our composite index
                            $channel['source_id'] = md5($channel['title'] . $channel['name'] . $channel['group']);

                            // Set channel number, if auto sort is enabled
                            if ($autoSort) {
                                $channel['sort'] = $channelNo;
                            }

                            // Auto-enable if in enabled group
                            if ($this->enabledGroups->contains($channel['group'] ?? '')) {
                                $channel['enabled'] = true;
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
                    'processing' => [
                        ...$playlist->processing ?? [],
                        'live_processing' => false,
                        'vod_processing' => false,
                    ]
                ]);

                // Fire the playlist synced event
                event(new SyncCompleted($this->playlist));
                return;
            }
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
                'processing' => [
                    ...$this->playlist->processing ?? [],
                    'live_processing' => false,
                    'vod_processing' => false,
                ]
            ]);

            // Fire the playlist synced event
            event(new SyncCompleted($this->playlist));
        }
        return;
    }

    /**
     * Process the Xtream API channel collections (live and VOD separately)
     */
    private function processXtreamChannelCollections(
        ?LazyCollection $liveCollection,
        ?LazyCollection $vodCollection,
        Playlist        $playlist,
        string          $batchNo,
        int             $userId,
        Carbon          $start,
        ?Collection     $seriesCategories = null,
        bool            $liveStreamsEnabled = false,
        bool            $vodStreamsEnabled = false,
        ?Collection     $liveGroups = null,
        ?Collection     $vodGroups = null,
    ) {
        // Get the playlist ID
        $playlistId = $playlist->id;

        // Setup group sort, if Playlist auto sort is enabled
        $groupOrder = null;
        if ($playlist->auto_sort) {
            $groupOrder = 1;
        }

        // Determine if we should create the channels and groups in the database
        $preProcessingLive = $this->preprocess
            && count($this->selectedGroups) === 0
            && count($this->includedGroupPrefixes) === 0;

        // Process live streams collection
        if ($liveStreamsEnabled && $liveCollection) {
            $liveCollection->groupBy('group')->chunk(10)->each(function (LazyCollection $grouped) use ($userId, $playlistId, $batchNo, $preProcessingLive, &$groupOrder, &$liveGroups) {
                $grouped->each(function ($channels, $groupName) use ($userId, $playlistId, $batchNo, $preProcessingLive, &$groupOrder, &$liveGroups) {
                    // Add group and associated channels
                    if (!$preProcessingLive) {
                        $group = Group::where([
                            'name_internal' => $groupName ?? '',
                            'playlist_id' => $playlistId,
                            'user_id' => $userId,
                            'custom' => false,
                            'type' => 'live',
                        ])->first();
                        if (!$group) {
                            $data = [
                                'name' => $groupName ?? '',
                                'name_internal' => $groupName ?? '',
                                'playlist_id' => $playlistId,
                                'user_id' => $userId,
                                'import_batch_no' => $batchNo,
                                'new' => true,
                                'type' => 'live', // Set group type to live
                            ];
                            if ($groupOrder !== null) {
                                $data['sort_order'] = $groupOrder++;
                            }
                            $group = Group::create($data);
                        } else {
                            $data = [
                                'import_batch_no' => $batchNo,
                                'new' => false,
                            ];
                            if ($groupOrder !== null) {
                                $data['sort_order'] = $groupOrder++;
                            }
                            $group->update($data);
                        }
                        $channels->chunk(50)->each(function ($chunk) use ($playlistId, $batchNo, $group) {
                            Job::create([
                                'title' => "Processing live channel import for group: {$group->name}",
                                'batch_no' => $batchNo,
                                'payload' => $chunk->toArray(),
                                'variables' => [
                                    'groupId' => $group->id,
                                    'groupName' => $group->name,
                                    'playlistId' => $playlistId,
                                    'type' => 'live', // Mark as live job
                                ]
                            ]);
                        });
                    }
                });
            });
        }

        // Determine if we should create the channels and groups in the database
        $preProcessingVod = $this->preprocess
            && count($this->selectedVodGroups) === 0
            && count($this->includedVodGroupPrefixes) === 0;

        // Process VOD streams collection
        if ($vodStreamsEnabled && $vodCollection) {
            $vodCollection->groupBy('group')->chunk(10)->each(function (LazyCollection $grouped) use ($userId, $playlistId, $batchNo, $preProcessingVod, &$groupOrder, &$vodGroups) {
                $grouped->each(function ($channels, $groupName) use ($userId, $playlistId, $batchNo, $preProcessingVod, &$groupOrder, &$vodGroups) {
                    // Add group and associated channels
                    if (!$preProcessingVod) {
                        $group = Group::where([
                            'name_internal' => $groupName ?? '',
                            'playlist_id' => $playlistId,
                            'user_id' => $userId,
                            'custom' => false,
                            'type' => 'vod',
                        ])->first();
                        if (!$group) {
                            $data = [
                                'name' => $groupName ?? '',
                                'name_internal' => $groupName ?? '',
                                'playlist_id' => $playlistId,
                                'user_id' => $userId,
                                'import_batch_no' => $batchNo,
                                'new' => true,
                                'type' => 'vod', // Set group type to vod
                            ];
                            if ($groupOrder !== null) {
                                $data['sort_order'] = $groupOrder++;
                            }
                            $group = Group::create($data);
                        } else {
                            $data = [
                                'import_batch_no' => $batchNo,
                                'new' => false,
                            ];
                            if ($groupOrder !== null) {
                                $data['sort_order'] = $groupOrder++;
                            }
                            $group->update($data);
                        }
                        $channels->chunk(50)->each(function ($chunk) use ($playlistId, $batchNo, $group) {
                            Job::create([
                                'title' => "Processing VOD channel import for group: {$group->name}",
                                'batch_no' => $batchNo,
                                'payload' => $chunk->toArray(),
                                'variables' => [
                                    'groupId' => $group->id,
                                    'groupName' => $group->name,
                                    'playlistId' => $playlistId,
                                    'type' => 'vod', // Mark as VOD job
                                ]
                            ]);
                        });
                    }
                });
            });
        }

        // Check if we should cleanup older source groups before creating new ones
        if (config('dev.cleanup_source_groups')) {
            // NOTE: Source groups used to be one type for Live and VOD both (merged).
            //       Now we process them separately to allow for different groupings.
            //       To support existing setups, we need to clear out Live groups that are VOD only, and vice versa.
            //       We'll run this before creating the source groups in case there is overlap, the group is re-added.
            foreach ($liveGroups->chunk(10) as $chunk) {
                SourceGroup::where('type', 'vod')
                    ->where('playlist_id', $playlistId)
                    ->whereIn('name', $chunk->pluck('category_name'))
                    ->delete();
            }
            foreach ($vodGroups->chunk(10) as $chunk) {
                SourceGroup::where('type', 'live')
                    ->where('playlist_id', $playlistId)
                    ->whereIn('name', $chunk->pluck('category_name'))
                    ->delete();
            }
        }

        // Create the source groups
        foreach ($liveGroups->chunk(50) as $chunk) {
            SourceGroup::upsert(
                collect($chunk)->map(function ($group) use ($playlistId) {
                    return [
                        'name' => $group['category_name'],
                        'playlist_id' => $playlistId,
                        'source_group_id' => $group['category_id'],
                        'type' => 'live',
                    ];
                })->toArray(),
                uniqueBy: ['name', 'playlist_id', 'type'],
                update: ['source_group_id'] // not used yet, but keep updated for future use
            );
        }
        foreach ($vodGroups->chunk(50) as $chunk) {
            SourceGroup::upsert(
                collect($chunk)->map(function ($group) use ($playlistId) {
                    return [
                        'name' => $group['category_name'],
                        'playlist_id' => $playlistId,
                        'source_group_id' => $group['category_id'],
                        'type' => 'vod',
                    ];
                })->toArray(),
                uniqueBy: ['name', 'playlist_id', 'type'],
                update: ['source_group_id'] // not used yet, but keep updated for future use
            );
        }

        // Create the series categories (needed for pre-processing)
        if ($seriesCategories && $seriesCategories->count() > 0) {
            foreach ($seriesCategories as $category) {
                // Need to create a source category entry
                $sc = SourceCategory::where([
                    'playlist_id' => $playlist->id,
                    'source_category_id' => $category['category_id'],
                ])->first();
                if (!$sc) {
                    SourceCategory::create([
                        'playlist_id' => $playlist->id,
                        'name' => $category['category_name'],
                        'source_category_id' => $category['category_id'],
                    ]);
                } else {
                    // Update name in case it has changed
                    // NOTE: this could cause pre-processing to remove previously selected categories if the name changes,
                    //       which means this now works the same as Source Categories in that regard...
                    $sc->update([
                        'name' => $category['category_name'],
                    ]);
                }

                // Only create category if not preprocessing, or if the category is selected
                if (!$this->preprocess || $this->shouldIncludeSeries($category['category_name'] ?? '')) {
                    $cat = Category::where([
                        'playlist_id' => $playlist->id,
                        'source_category_id' => $category['category_id'],
                    ])->first();
                    if (!$cat) {
                        $cat = Category::create([
                            'playlist_id' => $playlist->id,
                            'name' => $category['category_name'],
                            'name_internal' => $category['category_name'],
                            'source_category_id' => $category['category_id'],
                            'user_id' => $playlist->user_id,
                            'import_batch_no' => $batchNo,
                        ]);
                    } else {
                        $cat->update([
                            'name_internal' => $category['category_name'],
                            'import_batch_no' => $batchNo,
                        ]);
                    }
                }
            }
        }

        // Check if preprocessing, and no prefixes or groups selected yet
        if ($preProcessingLive && $preProcessingVod) {
            // Flag as complete and notify user
            $completedIn = $start->diffInSeconds(now());
            $completedInRounded = round($completedIn, 2);
            $playlist->update([
                'status' => Status::Completed,
                'channels' => 0, // not using...
                'synced' => now(),
                'errors' => null,
                'sync_time' => $completedIn,
                'progress' => $liveStreamsEnabled ? 100 : 0,
                'vod_progress' => $vodStreamsEnabled ? 100 : 0,
                'processing' => [
                    ...$playlist->processing ?? [],
                    'live_processing' => false,
                    'vod_processing' => false,
                ]
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
            return;
        }

        // Create the jobs array
        $jobs = [];

        // Check if we need to create a backup first (don't include first time syncs)
        if (!$this->isNew && $playlist->backup_before_sync) {
            $jobs[] = new CreateBackup(includeFiles: false);
        }

        // Get the live jobs for the batch
        if ($liveStreamsEnabled) {
            $liveJobsWhere = [
                ['batch_no', '=', $batchNo],
                ['variables', '!=', null],
                ['variables->type', '=', 'live'],
            ];
            $liveBatchCount = Job::where($liveJobsWhere)->count();
            $liveJobsBatch = Job::where($liveJobsWhere)->select('id')->cursor();
            $liveJobsBatch->chunk(100)->each(function ($chunk) use (&$jobs, $liveBatchCount) {
                $jobs[] = new ProcessM3uImportChunk($chunk->pluck('id')->toArray(), $liveBatchCount);
            });
        }

        // Get the VOD jobs for the batch
        if ($vodStreamsEnabled) {
            $vodJobsWhere = [
                ['batch_no', '=', $batchNo],
                ['variables', '!=', null],
                ['variables->type', '=', 'vod'],
            ];
            $vodBatchCount = Job::where($vodJobsWhere)->count();
            $vodJobsBatch = Job::where($vodJobsWhere)->select('id')->cursor();
            $vodJobsBatch->chunk(100)->each(function ($chunk) use (&$jobs, $vodBatchCount) {
                $jobs[] = new ProcessM3uVodImportChunk($chunk->pluck('id')->toArray(), $vodBatchCount);
            });
        }

        // Last job in the batch
        $jobs[] = new ProcessM3uImportComplete(
            userId: $userId,
            playlistId: $playlistId,
            batchNo: $batchNo,
            start: $start,
            maxHit: $this->maxItemsHit,
            isNew: $this->isNew,
            runningSeriesImport: $seriesCategories && $seriesCategories->count() > 0,
            runningLiveImport: $liveStreamsEnabled,
            runningVodImport: $vodStreamsEnabled,
        );

        // Add series processing to the chain, if passed in
        // This will run after the main channel import is complete
        if ($seriesCategories) {
            $categoryCount = $seriesCategories->count();
            $seriesCategories->each(function ($category, $index) use (&$jobs, $playlistId, $batchNo, $categoryCount) {
                if (!$this->preprocess || $this->shouldIncludeSeries($category['category_name'] ?? '')) {
                    // Check if category is auto-enabled
                    $autoEnable = $this->enabledCategories->contains($category['category_name'] ?? '');

                    // Create a job for each series category
                    $jobs[] = new ProcessM3uImportSeriesChunk(
                        [
                            'categoryId' => $category['category_id'],
                            'categoryName' => $category['category_name'],
                            'playlistId' => $playlistId,
                        ],
                        $categoryCount,
                        $batchNo,
                        $index,
                        $autoEnable
                    );
                }
            });

            // Add series processing to the chain
            $jobs[] = new ProcessM3uImportSeriesComplete(
                playlist: $playlist,
                batchNo: $batchNo
            );
        }

        // Start the chain!
        Bus::chain($jobs)
            ->onConnection('redis') // force to use redis connection
            ->onQueue('import')
            ->catch(function (Throwable $e) use ($playlist) {
                $error = "Error processing \"{$playlist->name}\": {$e->getMessage()}";
                Log::error($error);
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
                    'processing' => [
                        ...$playlist->processing ?? [],
                        'live_processing' => false,
                        'vod_processing' => false,
                    ]
                ]);
                event(new SyncCompleted($playlist));
            })->dispatch();
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

        // Update progress
        $playlist->update(['progress' => 15]);

        // Setup group sort, if Playlist auto sort is enabled
        $groupOrder = null;
        if ($playlist->auto_sort) {
            $groupOrder = 1;
        }

        // Determine if we should create the channels and groups in the database
        $preProcessing = $this->preprocess
            && count($this->selectedGroups) === 0
            && count($this->includedGroupPrefixes) === 0;

        // Process the collection
        $collection->groupBy('group')->chunk(10)->each(function (LazyCollection $grouped) use ($userId, $playlistId, $batchNo, $preProcessing, &$groupOrder) {
            $grouped->each(function ($channels, $groupName) use ($userId, $playlistId, $batchNo, $preProcessing, &$groupOrder) {
                // Add group and associated channels
                if (!$preProcessing) {
                    $group = Group::where([
                        'name_internal' => $groupName ?? '',
                        'playlist_id' => $playlistId,
                        'user_id' => $userId,
                        'custom' => false,
                        'type' => 'live', // default to live type
                    ])->first();
                    if (!$group) {
                        $data = [
                            'name' => $groupName ?? '',
                            'name_internal' => $groupName ?? '',
                            'playlist_id' => $playlistId,
                            'user_id' => $userId,
                            'import_batch_no' => $batchNo,
                            'new' => true,
                            'type' => 'live', // default to live type
                        ];
                        if ($groupOrder !== null) {
                            $data['sort_order'] = $groupOrder++;
                        }
                        $group = Group::create($data);
                    } else {
                        $data = [
                            'import_batch_no' => $batchNo,
                            'new' => false,
                        ];
                        if ($groupOrder !== null) {
                            $data['sort_order'] = $groupOrder++;
                        }
                        $group->update($data);
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
                }
            });
        });

        // Remove duplicate groups
        $groups = array_values(array_unique($this->groups));

        // If m3u parser set, check if any errors were logged
        if ($this->m3uParser) {
            $errors = $this->m3uParser->getParseErrors();
            if (count($errors) > 0) {
                Notification::make()
                    ->warning()
                    ->title('Error(s) detected during parsing')
                    ->body("While parsing the playlist, please check your notifications for details.")
                    ->broadcast($playlist->user);
                Notification::make()
                    ->warning()
                    ->title('Error(s) detected during parsing')
                    ->body("There were issues with the following lines, and they will not be imported due to formatting issues: " . implode('; ', $errors))
                    ->sendToDatabase($playlist->user);
            }
        }

        // Create the source groups
        // NOTE: Need to call **AFTER** the channel loop has been executed.
        //       If called before, the loop will not have run yet, and no groups will be created
        foreach (array_chunk($groups, 50) as $chunk) {
            SourceGroup::upsert(
                collect($chunk)->map(function ($groupName) use ($playlistId) {
                    return [
                        'name' => $groupName,
                        'playlist_id' => $playlistId,
                        'type' => 'live',
                    ];
                })->toArray(),
                uniqueBy: ['name', 'playlist_id', 'type'],
                update: []
            );
        }

        // Check if preprocessing, and no prefixes or groups selected yet
        if ($preProcessing) {
            // Flag as complete and notify user
            $completedIn = $start->diffInSeconds(now());
            $completedInRounded = round($completedIn, 2);
            $playlist->update([
                'status' => Status::Completed,
                'channels' => 0, // not using...
                'synced' => now(),
                'errors' => null,
                'sync_time' => $completedIn,
                'progress' => 100,
                'processing' => [
                    ...$playlist->processing ?? [],
                    'live_processing' => false,
                    'vod_processing' => false,
                ]
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
            return;
        }

        // Create the jobs array
        $jobs = [];

        // Check if we need to create a backup first (don't include first time syncs)
        if (!$this->isNew && $playlist->backup_before_sync) {
            $jobs[] = new CreateBackup(includeFiles: false);
        }

        // Get the jobs for the batch
        $jobsWhere = [
            ['batch_no', '=', $batchNo],
            ['variables', '!=', null],
        ];
        $batchCount = Job::where($jobsWhere)->count();
        $jobsBatch = Job::where($jobsWhere)->select('id')->cursor();
        $jobsBatch->chunk(100)->each(function ($chunk) use (&$jobs, $batchCount) {
            $jobs[] = new ProcessM3uImportChunk($chunk->pluck('id')->toArray(), $batchCount);
        });

        // Last job in the batch
        $jobs[] = new ProcessM3uImportComplete(
            userId: $userId,
            playlistId: $playlistId,
            batchNo: $batchNo,
            start: $start,
            maxHit: $this->maxItemsHit,
            isNew: $this->isNew,
            runningSeriesImport: false, // No series import for M3U imports
        );

        // Start the chain!
        Bus::chain($jobs)
            ->onConnection('redis') // force to use redis connection
            ->onQueue('import')
            ->catch(function (Throwable $e) use ($playlist) {
                $error = "Error processing \"{$playlist->name}\": {$e->getMessage()}";
                Log::error($error);
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
                    'processing' => [
                        ...$playlist->processing ?? [],
                        'live_processing' => false,
                        'vod_processing' => false,
                    ]
                ]);
                event(new SyncCompleted($playlist));
            })->dispatch();
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

    /**
     * Determine if the VOD channel should be included
     *
     * @param string $groupName
     */
    private function shouldIncludeVod($groupName): bool
    {
        // Check if group is selected...
        if (in_array(
            $groupName,
            $this->selectedVodGroups
        )) {
            // Group selected directly
            return true;
        } else {
            // ...if group not selected, check if group starts with any of the included prefixes
            // (only check if the group isn't directly included already)
            foreach ($this->includedVodGroupPrefixes as $pattern) {
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

    /**
     * Determine if the Series should be included
     *
     * @param string $categoryName
     */
    private function shouldIncludeSeries($categoryName): bool
    {
        // Check if category is selected...
        if (in_array(
            $categoryName,
            $this->selectedCategories
        )) {
            // Category selected directly
            return true;
        } else {
            // ...if category not selected, check if category starts with any of the included prefixes
            // (only check if the category isn't directly included already)
            foreach ($this->includedCategoryPrefixes as $pattern) {
                if ($this->useRegex) {
                    // Escape existing delimiters in user input
                    $delimiter = '/';
                    $escapedPattern = str_replace($delimiter, '\\' . $delimiter, $pattern);
                    $finalPattern = $delimiter . $escapedPattern . $delimiter . 'u';
                    if (preg_match($finalPattern, $categoryName)) {
                        return true;
                    }
                } else {
                    // Use simple string prefix matching
                    if (str_starts_with($categoryName, $pattern)) {
                        return true;
                    }
                }
            }
        }
        return false;
    }
}
