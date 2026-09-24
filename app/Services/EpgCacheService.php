<?php

namespace App\Services;

use App\Enums\EpgSourceType;
use App\Enums\Status;
use App\Facades\PlaylistFacade;
use App\Models\CustomPlaylist;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\EpgProgramme;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Support\EpgProgrammeNormalizer;
use App\Support\EpisodeNumberParser;
use Carbon\Carbon;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use XMLReader;

/**
 * Service to handle EPG caching operations
 */
class EpgCacheService
{
    private const CACHE_VERSION = 'v2';

    /** File names of the one canonical cache, owned by {@see EpgCacheStorage}. */
    private const CHANNELS_FILE = EpgCacheStorage::CHANNELS_FILE;

    private const METADATA_FILE = EpgCacheStorage::METADATA_FILE;

    private const MAX_PROGRAMMES = 10000000; // Safety limit

    /**
     * Single-file SQLite store of all cached programmes for an EPG, and the only
     * copy of that data on disk. Replaces the legacy per-date
     * `programmes-{date}.jsonl` files and their `.index.json` offset index;
     * reads fall back to the JSONL scan when this file is absent (v1 caches, or
     * v2 caches written before the SQLite switch).
     *
     * @see EpgProgrammeStore
     */
    private const PROGRAMMES_DB_FILE = EpgCacheStorage::PROGRAMMES_DB_FILE;

    /**
     * Per-instance memo of opened {@see EpgProgrammeStore} read handles, keyed
     * by EPG id (null = no SQLite store, use the JSONL fallback). A single
     * request reads the same store repeatedly (the batch endpoint alone reads
     * date + next day for up to 100 channels).
     *
     * @var array<int, EpgProgrammeStore|null>
     */
    private array $programmeStores = [];

    /**
     * Per-instance memo of the resolved active cache directories, keyed by EPG
     * id, so one request keeps reading the same directory. There is one
     * canonical cache per EPG; a rebuild replaces the files inside it, and this
     * service forgets the memo after publishing a rebuild itself.
     *
     * @var array<int, string>
     */
    private array $activeCacheDirectories = [];

    /**
     * Returns the directory of the best available cache: current version first,
     * then each legacy version in order. Falls back to the current version path
     * (which may not yet exist) when no cache has been written yet.
     *
     * Used by read operations so existing v1 caches continue to be served until
     * the next scheduled sync writes a fresh v2 cache.
     */
    private function getActiveCacheDir(Epg $epg): string
    {
        if (array_key_exists($epg->id, $this->activeCacheDirectories)) {
            return $this->activeCacheDirectories[$epg->id];
        }

        return $this->activeCacheDirectories[$epg->id] = $this->cacheStorage()->resolve($epg);
    }

    private function cacheStorage(): EpgCacheStorage
    {
        return app(EpgCacheStorage::class);
    }

    /**
     * Get cache file path for read operations (uses the active/best available version).
     */
    private function getActiveCacheFilePath(Epg $epg, string $filename): string
    {
        return $this->getActiveCacheDir($epg).'/'.$filename;
    }

    /**
     * Check if cache is valid
     */
    public function isCacheValid(Epg $epg): bool
    {
        // CRITICAL: If EPG is currently being processed, cache is NOT valid
        // This prevents race condition where we try to read a cache being regenerated
        if ($epg->processing_phase === 'cache' || $epg->status === Status::Processing) {
            return false;
        }

        $metadataPath = $this->getActiveCacheFilePath($epg, self::METADATA_FILE);

        if (! Storage::disk('local')->exists($metadataPath)) {
            return false;
        }

        try {
            // Use json_decode for metadata parsing since it will be a small file
            $metadata = json_decode(Storage::disk('local')->get($metadataPath), true);

            // Check if EPG source file has been modified since cache was created.
            // If the source file no longer exists (e.g. cleaned up after caching,
            // or lost on a volume restart), treat the existing cache as still valid.
            $epgFilePath = Storage::disk('local')->path($epg->file_path);
            if (file_exists($epgFilePath)) {
                $epgFileModified = filemtime($epgFilePath);
                $cacheCreated = $metadata['cache_created'] ?? 0;

                return $epgFileModified <= $cacheCreated;
            }

            return true;
        } catch (Exception $e) {
            Log::warning("Invalid cache metadata for EPG {$epg->uuid}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Cache EPG data from XML file
     */
    public function cacheEpgData(Epg $epg): bool
    {
        // Get the content
        $filePath = null;
        if ($epg->source_type === EpgSourceType::SCHEDULES_DIRECT || ($epg->url && str_starts_with($epg->url, 'http'))) {
            $filePath = Storage::disk('local')->path($epg->file_path);
        } elseif ($epg->uploads && Storage::disk('local')->exists($epg->uploads)) {
            $filePath = Storage::disk('local')->path($epg->uploads);
        } elseif ($epg->url) {
            $filePath = $epg->url;
        }
        if (! file_exists($filePath)) {
            Log::error("EPG file not found: {$filePath}");

            return false;
        }
        try {
            Log::debug("Starting EPG cache generation for {$epg->name}");
            set_time_limit(60 * 120); // 120 minutes

            // Get the channel count for progress tracking
            $totalChannels = $epg->channel_count ?? $epg->channels()->count();
            $totalProgrammes = $epg->programme_count ?? 150000; // Default estimate

            // Build every file of the rebuild next to the canonical cache and
            // swap them in only once parsing succeeded: the one canonical cache
            // stays readable - and untouched - for the whole rebuild.
            $storage = $this->cacheStorage();
            $directory = $storage->beginRebuild($epg);
            $staged = [
                self::PROGRAMMES_DB_FILE => $storage->stagedPath($directory, self::PROGRAMMES_DB_FILE),
                self::CHANNELS_FILE => $storage->stagedPath($directory, self::CHANNELS_FILE),
                self::METADATA_FILE => $storage->stagedPath($directory, self::METADATA_FILE),
            ];

            try {
                // Parse and save channels and programmes in a single pass
                Log::debug("Parsing EPG data for {$epg->name}");
                $stats = $this->parseAndSaveEpgDataSinglePass($epg, $filePath, $totalChannels, $totalProgrammes, $staged);
                Log::debug("Processed {$stats['channels']} channels and {$stats['programmes']} programmes across {$stats['date_count']} dates");

                // Save metadata
                $metadata = [
                    'cache_created' => time(),
                    'cache_version' => self::CACHE_VERSION,
                    'epg_uuid' => $epg->uuid,
                    'total_channels' => $stats['channels'],
                    'total_programmes' => $stats['programmes'],
                    'programme_date_range' => $stats['date_range'],
                ];

                Storage::disk('local')->put(
                    $staged[self::METADATA_FILE],
                    json_encode($metadata, JSON_PRETTY_PRINT)
                );

                $storage->publishRebuild($epg, $staged);
            } catch (\Throwable $e) {
                // Never leave staging files behind for a rebuild that failed.
                $storage->discardStaged($staged);

                throw $e;
            }

            // Drop any read handle memoized before this rebuild so subsequent
            // local reads resolve the rebuilt cache.
            $this->forgetProgrammeStore($epg);
            $this->forgetActiveCacheDirectory($epg);

            // Flag EPG as cached
            $epg->update([
                'is_cached' => true,
                'cache_progress' => 100,
                'cache_meta' => $metadata,
                // Update counts
                'channel_count' => $stats['channels'],
                'programme_count' => $stats['programmes'],
            ]);

            Log::debug('EPG cache generated successfully', $metadata);

            // Populate epg_programmes DB table for DVR-enabled playlists
            $this->populateDvrProgrammes($epg);

            // Release the read handle opened while populating DVR so a long-lived
            // service instance (a command caching several EPGs) does not hold one
            // open PDO connection per EPG for the rest of its life.
            $this->forgetProgrammeStore($epg);

            return true;
        } catch (\Throwable $e) {
            // \Throwable, not Exception: a TypeError/Error mid-parse (or a failed
            // store swap) must still leave the EPG flagged as a failed cache
            // rather than propagating past a "success" metadata write.
            Log::error("Failed to cache EPG data for {$epg->name}: {$e->getMessage()}");

            return false;
        }
    }

    /**
     * Apply a single XMLTV programme child element's value to the $programme array.
     *
     * Shared by both the single-pass writer and the stream generator to avoid
     * maintaining two identical switch blocks.
     *
     * @param  array<string, mixed>  $programme
     */
    private function applyProgrammeElement(XMLReader $reader, string $elementName, array &$programme): void
    {
        switch ($elementName) {
            case 'title':
                $programme['title'] = trim($reader->readString() ?: '');
                break;
            case 'sub-title':
                $programme['subtitle'] = trim($reader->readString() ?: '');
                break;
            case 'desc':
                $programme['desc'] = trim($reader->readString() ?: '');
                break;
            case 'category':
                if (! $programme['category']) {
                    $programme['category'] = trim($reader->readString() ?: '');
                }
                break;
            case 'icon':
                if (! $programme['icon']) {
                    $programme['icon'] = trim($reader->getAttribute('src') ?: '');
                } else {
                    $imageUrl = trim($reader->getAttribute('src') ?: '');
                    if ($imageUrl) {
                        $programme['images'][] = [
                            'url' => $imageUrl,
                            'type' => trim($reader->getAttribute('type') ?: 'poster'),
                            'width' => (int) ($reader->getAttribute('width') ?: 0),
                            'height' => (int) ($reader->getAttribute('height') ?: 0),
                            'orient' => trim($reader->getAttribute('orient') ?: 'P'),
                            'size' => (int) ($reader->getAttribute('size') ?: 1),
                        ];
                    }
                }
                break;
            case 'new':
                $programme['new'] = true;
                break;
            case 'previously-shown':
                // The <previously-shown> element may carry start/channel attributes;
                // only the boolean presence is recorded - attributes are intentionally ignored.
                $programme['previously_shown'] = true;
                break;
            case 'premiere':
                // The <premiere> element may carry text content (a description);
                // only the boolean presence is recorded - content is intentionally ignored.
                $programme['premiere'] = true;
                break;
            case 'episode-num':
                $episodeNumbers = EpisodeNumberNormalizer::normalize([[
                    'system' => $reader->getAttribute('system'),
                    'value' => $reader->readString(),
                ]]);
                if ($episodeNumbers !== []) {
                    $episodeNumber = $episodeNumbers[0];
                    if ($programme['episode_num'] === '') {
                        $programme['episode_num'] = $episodeNumber['value'];
                    }
                    $programme['episode_nums'][] = $episodeNumber;
                }
                break;
            case 'url':
                $urlValue = trim($reader->readString() ?: '');
                $urlSystem = mb_strtolower(trim((string) ($reader->getAttribute('system') ?: '')));
                // Validate to prevent storing malformed or unsafe URL values from untrusted EPG feeds.
                if ($urlValue !== '' && filter_var($urlValue, FILTER_VALIDATE_URL)) {
                    $programme['urls'][] = [
                        'system' => $urlSystem,
                        'value' => $urlValue,
                    ];
                }
                break;
            case 'date':
                $dateValue = trim($reader->readString() ?: '');
                if ($programme['production_year'] === null && preg_match('/^(\d{4})/', $dateValue, $matches)) {
                    $programme['production_year'] = (int) $matches[1];
                }
                break;
            case 'rating':
                while (@$reader->read()) {
                    if ($reader->nodeType == XMLReader::ELEMENT && $reader->name === 'value') {
                        $programme['rating'] = trim($reader->readString() ?: '');
                        break;
                    } elseif ($reader->nodeType == XMLReader::END_ELEMENT && $reader->name === 'rating') {
                        break;
                    }
                }
                break;
        }
    }

    /**
     * Parse and save EPG data in a single pass (optimized for performance)
     * This method parses both channels and programmes in one pass through the file,
     * reducing processing time by ~50% compared to double parsing.
     *
     * @param  array<string, string>  $staged  canonical filename => staging path written by this rebuild
     */
    private function parseAndSaveEpgDataSinglePass(Epg $epg, string $filePath, int $totalChannels, int $totalProgrammes, array $staged): array
    {
        $reader = new XMLReader;
        $reader->open('compress.zlib://'.$filePath);

        $channelCount = 0;
        $programmeCount = 0;
        $channelBatchSize = 5000; // Larger batch for fewer writes
        $channelBatch = [];
        $dateRangeTracker = ['min_date' => null, 'max_date' => null];
        $processedDates = [];
        $lastProgressUpdate = 0;
        $progressUpdateInterval = 5000; // Update progress every 5000 items instead of 50

        $store = new EpgProgrammeStore;
        $store->beginWrite(Storage::disk('local')->path($staged[self::PROGRAMMES_DB_FILE]));

        try {
            while (@$reader->read()) {
                // Process channels
                if ($reader->nodeType == XMLReader::ELEMENT && $reader->name === 'channel') {
                    $channelId = trim($reader->getAttribute('id') ?: '');
                    $innerXML = $reader->readOuterXml();
                    $innerReader = new XMLReader;
                    $innerReader->xml($innerXML);

                    $channel = [
                        'id' => $channelId,
                        'display_name' => '',
                        'icon' => '',
                        'lang' => 'en',
                    ];

                    while (@$innerReader->read()) {
                        if ($innerReader->nodeType == XMLReader::ELEMENT) {
                            switch ($innerReader->name) {
                                case 'display-name':
                                    if (! $channel['display_name']) {
                                        $channel['display_name'] = trim($innerReader->readString() ?: '');
                                        $channel['lang'] = trim($innerReader->getAttribute('lang') ?: '') ?: 'en';
                                    }
                                    break;
                                case 'icon':
                                    $channel['icon'] = trim($innerReader->getAttribute('src') ?: '');
                                    break;
                            }
                        }
                    }
                    $innerReader->close();

                    if ($channelId) {
                        $channelBatch[$channelId] = $channel;
                        $channelCount++;

                        // Save in larger batches for better performance
                        if (count($channelBatch) >= $channelBatchSize) {
                            $this->saveChannelBatchOptimized($staged[self::CHANNELS_FILE], $channelBatch);
                            $channelBatch = [];
                        }
                    }
                }
                // Process programmes
                elseif ($reader->nodeType == XMLReader::ELEMENT && $reader->name === 'programme') {
                    $programmeCount++;

                    // Safety limit
                    if ($programmeCount > self::MAX_PROGRAMMES) {
                        Log::warning("Programme processing limit reached at {$programmeCount}");
                        break;
                    }

                    $channelId = trim($reader->getAttribute('channel') ?: '');
                    $start = trim($reader->getAttribute('start') ?: '');
                    $stop = trim($reader->getAttribute('stop') ?: '');

                    if (! $channelId || ! $start) {
                        continue;
                    }

                    $startDateTime = $this->parseXmltvDateTime($start);
                    $stopDateTime = $stop ? $this->parseXmltvDateTime($stop) : null;

                    if (! $startDateTime) {
                        continue;
                    }

                    $date = $startDateTime->format('Y-m-d');

                    // Track date range
                    if ($dateRangeTracker['min_date'] === null || $date < $dateRangeTracker['min_date']) {
                        $dateRangeTracker['min_date'] = $date;
                    }
                    if ($dateRangeTracker['max_date'] === null || $date > $dateRangeTracker['max_date']) {
                        $dateRangeTracker['max_date'] = $date;
                    }

                    $innerXML = $reader->readOuterXml();
                    $innerReader = new XMLReader;
                    $innerReader->xml($innerXML);

                    $programme = EpgProgrammeStore::EMPTY_PROGRAMME;
                    $programme['channel'] = $channelId;
                    $programme['start'] = $startDateTime->toISOString();
                    $programme['stop'] = $stopDateTime ? $stopDateTime->toISOString() : null;

                    while (@$innerReader->read()) {
                        if ($innerReader->nodeType == XMLReader::ELEMENT) {
                            $this->applyProgrammeElement($innerReader, $innerReader->name, $programme);
                        }
                    }
                    $innerReader->close();

                    if ($programme['title']) {
                        $store->insert(
                            $channelId,
                            $date,
                            $startDateTime->getTimestamp(),
                            $stopDateTime?->getTimestamp(),
                            $programme,
                        );
                        $processedDates[$date] = true;
                    }

                    // Update progress less frequently (every 5000 items instead of 50)
                    $totalProcessed = $channelCount + $programmeCount;
                    if ($totalProcessed - $lastProgressUpdate >= $progressUpdateInterval) {
                        $estimatedTotal = $totalChannels + $totalProgrammes;
                        $progress = $estimatedTotal > 0
                            ? min(99, round(($totalProcessed / $estimatedTotal) * 99))
                            : 99;
                        $epg->update(['cache_progress' => $progress]);
                        $lastProgressUpdate = $totalProcessed;

                        // Garbage collection less frequently
                        if (function_exists('gc_collect_cycles')) {
                            gc_collect_cycles();
                        }
                    }
                }
            }

            // Save any remaining channels
            if (! empty($channelBatch)) {
                $this->saveChannelBatchOptimized($staged[self::CHANNELS_FILE], $channelBatch);
            }

            // Commit and index the staged store. Publishing it is the caller's
            // job: the file only becomes canonical once the rebuild is complete.
            $store->finish();
        } catch (\Throwable $e) {
            $store->discard();
            throw $e;
        } finally {
            $reader->close();
        }

        return [
            'channels' => $channelCount,
            'programmes' => $programmeCount,
            'date_count' => count($processedDates),
            'date_range' => $dateRangeTracker,
        ];
    }

    /**
     * Merge one batch of channels into the rebuild's staged channels file.
     *
     * @param  string  $channelsPath  canonical-relative path of the staged channels file
     */
    private function saveChannelBatchOptimized(string $channelsPath, array $channelBatch): void
    {
        $fullPath = Storage::disk('local')->path($channelsPath);

        // Ensure directory exists
        $dir = dirname($fullPath);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Check if file already exists to determine if this is truly the first write
        $fileExists = file_exists($fullPath);

        if (! $fileExists) {
            // First write - create new file
            file_put_contents($fullPath, json_encode($channelBatch, JSON_UNESCAPED_UNICODE), LOCK_EX);
        } else {
            // Subsequent batches - append using simple merge
            // This is acceptable for channels as there are usually only thousands, not millions
            try {
                $existing = json_decode(file_get_contents($fullPath), true) ?: [];
                $merged = array_merge($existing, $channelBatch);
                file_put_contents($fullPath, json_encode($merged, JSON_UNESCAPED_UNICODE), LOCK_EX);
            } catch (Exception $e) {
                Log::error("Failed to merge channel batch: {$e->getMessage()}");
                // Fallback: create new file if merge fails
                file_put_contents($fullPath, json_encode($channelBatch, JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        }
    }

    /**
     * Open (once per request) the SQLite programme store for an EPG, or null
     * when the active cache predates the SQLite switch (v1 caches, or v2 caches
     * still holding `programmes-{date}.jsonl`) so callers use the JSONL scan.
     */
    private function programmeStore(Epg $epg): ?EpgProgrammeStore
    {
        if (array_key_exists($epg->id, $this->programmeStores)) {
            return $this->programmeStores[$epg->id];
        }

        $path = $this->getActiveCacheFilePath($epg, self::PROGRAMMES_DB_FILE);
        if (! Storage::disk('local')->exists($path)) {
            return $this->programmeStores[$epg->id] = null;
        }

        try {
            return $this->programmeStores[$epg->id] = EpgProgrammeStore::openRead(
                Storage::disk('local')->path($path)
            );
        } catch (Exception $e) {
            Log::warning("Failed to open EPG programme store for {$epg->uuid}: {$e->getMessage()}");

            return $this->programmeStores[$epg->id] = null;
        }
    }

    /**
     * Close and forget the memoized programme store read handle for an EPG.
     * Called after a cache rebuild so the next {@see programmeStore()} reopens
     * against the freshly written SQLite file, and so a long-lived service
     * instance (e.g. a command caching several EPGs) does not accumulate open
     * PDO handles.
     */
    private function forgetProgrammeStore(Epg $epg): void
    {
        if (array_key_exists($epg->id, $this->programmeStores)) {
            $this->programmeStores[$epg->id]?->close();
            unset($this->programmeStores[$epg->id]);
        }
    }

    /**
     * Forget the cache directory memoized by this service after it publishes a
     * rebuild itself.
     */
    private function forgetActiveCacheDirectory(Epg $epg): void
    {
        unset($this->activeCacheDirectories[$epg->id]);
    }

    /**
     * Stream parse programmes from EPG file using generators.
     *
     * Visibility is protected (not private) to allow subclassing in tests
     * without resorting to reflection.
     */
    protected function parseProgrammesStream(string $filePath): Generator
    {
        $programReader = new XMLReader;
        $programReader->open('compress.zlib://'.$filePath);
        $processedCount = 0;

        while (@$programReader->read()) {
            if ($programReader->nodeType == XMLReader::ELEMENT && $programReader->name === 'programme') {
                $processedCount++;

                // Safety limit
                if ($processedCount > self::MAX_PROGRAMMES) {
                    Log::warning("Programme processing limit reached at {$processedCount}");
                    break;
                }

                $channelId = trim($programReader->getAttribute('channel') ?: '');
                $start = trim($programReader->getAttribute('start') ?: '');
                $stop = trim($programReader->getAttribute('stop') ?: '');

                if (! $channelId || ! $start) {
                    continue;
                }

                $startDateTime = $this->parseXmltvDateTime($start);
                $stopDateTime = $stop ? $this->parseXmltvDateTime($stop) : null;

                if (! $startDateTime) {
                    continue;
                }

                $innerXML = $programReader->readOuterXml();
                $innerReader = new XMLReader;
                $innerReader->xml($innerXML);

                $programme = [
                    'channel' => $channelId,
                    'start' => $startDateTime->toISOString(),
                    'stop' => $stopDateTime ? $stopDateTime->toISOString() : null,
                    'title' => '',
                    'subtitle' => '',
                    'desc' => '',
                    'category' => '',
                    'episode_num' => '',
                    'episode_nums' => [],
                    'rating' => '',
                    'icon' => '',
                    'images' => [], // New: store program artwork
                    'new' => false,
                    'previously_shown' => false,
                    'premiere' => false,
                    'urls' => [],
                    'production_year' => null,
                ];

                while (@$innerReader->read()) {
                    if ($innerReader->nodeType == XMLReader::ELEMENT) {
                        $this->applyProgrammeElement($innerReader, $innerReader->name, $programme);
                    }
                }
                $innerReader->close();

                if ($programme['title']) {
                    yield $programme;
                }
            }
        }
        $programReader->close();
    }

    /**
     * Get cached channels
     */
    public function getCachedChannels(Epg $epg, int $page = 1, int $perPage = 50): array
    {
        $channelsPath = $this->getActiveCacheFilePath($epg, self::CHANNELS_FILE);

        if (! Storage::disk('local')->exists($channelsPath)) {
            return [
                'channels' => [],
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total_channels' => 0,
                    'returned_channels' => 0,
                    'has_more' => false,
                    'next_page' => null,
                ],
            ];
        }

        try {
            // Use JsonMachine for memory-efficient parsing - single iteration
            $channelsStream = Items::fromFile(
                Storage::disk('local')->path($channelsPath),
                ['decoder' => new ExtJsonDecoder(true)]
            );

            // Single pass through the data to collect pagination info
            $channels = [];
            $totalChannels = 0;
            $skip = ($page - 1) * $perPage;
            $collected = 0;
            $hasMore = false;

            foreach ($channelsStream as $channelId => $channel) {
                $totalChannels++;

                // Skip to the desired page
                if ($totalChannels <= $skip) {
                    continue;
                }

                // Collect channels for this page
                if ($collected < $perPage) {
                    $channels[$channelId] = $channel;
                    $collected++;
                } else {
                    // We have enough for this page, and there's at least one more
                    $hasMore = true;
                    break;
                }
            }

            return [
                'channels' => $channels,
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total_channels' => $skip + $collected + ($hasMore ? 1 : 0), // Estimate
                    'returned_channels' => count($channels),
                    'has_more' => $hasMore,
                    'next_page' => $hasMore ? $page + 1 : null,
                ],
            ];
        } catch (Exception $e) {
            Log::error("Error reading cached channels: {$e->getMessage()}");

            return [
                'channels' => [],
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total_channels' => 0,
                    'returned_channels' => 0,
                    'has_more' => false,
                    'next_page' => null,
                ],
            ];
        }
    }

    /**
     * Get cached programmes for a specific date and channels
     */
    public function getCachedProgrammes(Epg $epg, string $date, array $channelIds = []): array
    {
        $store = $this->programmeStore($epg);
        if ($store !== null) {
            try {
                return $store->read($date, array_values($channelIds));
            } catch (Exception $e) {
                Log::error("Error reading programme store for date {$date}: {$e->getMessage()}");

                return [];
            }
        }

        // Legacy JSONL scan: v1 caches, or v2 caches written before the SQLite switch.
        $programmesPath = $this->getActiveCacheFilePath($epg, "programmes-{$date}.jsonl");

        if (! Storage::disk('local')->exists($programmesPath)) {
            return [];
        }

        try {
            $programmes = [];
            $fullPath = Storage::disk('local')->path($programmesPath);

            // Read JSONL file line by line
            if (($handle = fopen($fullPath, 'r')) !== false) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }

                    try {
                        $record = json_decode($line, true);
                        if (! $record || ! isset($record['channel']) || ! isset($record['programme'])) {
                            continue;
                        }

                        $channelId = $record['channel'];
                        $programme = $record['programme'];

                        // Filter by channel IDs if provided
                        if (! empty($channelIds) && ! in_array($channelId, $channelIds)) {
                            continue;
                        }

                        if (! isset($programmes[$channelId])) {
                            $programmes[$channelId] = [];
                        }
                        $programmes[$channelId][] = $programme;
                    } catch (Exception $lineError) {
                        Log::warning("Failed to parse programme line: {$lineError->getMessage()}");

                        continue;
                    }
                }
                fclose($handle);
            }

            return $programmes;
        } catch (Exception $e) {
            Log::error("Error reading cached programmes for date {$date}: {$e->getMessage()}");

            return [];
        }
    }

    /**
     * Get cached programmes for a date range and channels
     */
    public function getCachedProgrammesRange(Epg $epg, string $startDate, string $endDate, array $channelIds = []): array
    {
        $allProgrammes = [];
        $currentDate = Carbon::parse($startDate);
        $endDateCarbon = Carbon::parse($endDate);

        while ($currentDate <= $endDateCarbon) {
            $dateStr = $currentDate->format('Y-m-d');

            // Stream programmes for this date
            foreach ($this->streamCachedProgrammesForDate($epg, $dateStr, $channelIds) as $channelId => $programmes) {
                if (! isset($allProgrammes[$channelId])) {
                    $allProgrammes[$channelId] = [];
                }
                $allProgrammes[$channelId] = array_merge($allProgrammes[$channelId], $programmes);
            }
            $currentDate->addDay();
        }

        // Sort programmes by start time within each channel using generators
        foreach ($allProgrammes as $channelId => $programmes) {
            usort($allProgrammes[$channelId], function ($a, $b) {
                return strcmp($a['start'], $b['start']);
            });
        }

        return $allProgrammes;
    }

    /**
     * Stream cached programmes for a specific date using generators with JSONL format
     */
    private function streamCachedProgrammesForDate(Epg $epg, string $date, array $channelIds = []): Generator
    {
        $store = $this->programmeStore($epg);
        if ($store !== null) {
            try {
                foreach ($store->read($date, array_values($channelIds)) as $channelId => $programmes) {
                    yield $channelId => $programmes;
                }
            } catch (Exception $e) {
                Log::error("Error streaming programme store for date {$date}: {$e->getMessage()}");
            }

            return;
        }

        // Legacy JSONL scan: v1 caches, or v2 caches written before the SQLite switch.
        $programmesPath = $this->getActiveCacheFilePath($epg, "programmes-{$date}.jsonl");
        if (! Storage::disk('local')->exists($programmesPath)) {
            return;
        }

        try {
            $channelProgrammes = [];
            $fullPath = Storage::disk('local')->path($programmesPath);

            // Read JSONL file line by line
            if (($handle = fopen($fullPath, 'r')) !== false) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }

                    try {
                        $record = json_decode($line, true);
                        if (! $record || ! isset($record['channel']) || ! isset($record['programme'])) {
                            continue;
                        }

                        $channelId = $record['channel'];
                        $programme = $record['programme'];

                        // Filter by channel IDs if provided
                        if (! empty($channelIds) && ! in_array($channelId, $channelIds)) {
                            continue;
                        }

                        if (! isset($channelProgrammes[$channelId])) {
                            $channelProgrammes[$channelId] = [];
                        }
                        $channelProgrammes[$channelId][] = $programme;
                    } catch (Exception $lineError) {
                        Log::warning("Failed to parse programme line: {$lineError->getMessage()}");

                        continue;
                    }
                }
                fclose($handle);
            }

            // Yield each channel's programmes
            foreach ($channelProgrammes as $channelId => $programmes) {
                yield $channelId => $programmes;
            }
        } catch (Exception $e) {
            Log::error("Error streaming cached programmes for date {$date}: {$e->getMessage()}");
        }
    }

    /**
     * Get cache metadata
     */
    public function getCacheMetadata(Epg $epg): ?array
    {
        $metadataPath = $this->getActiveCacheFilePath($epg, self::METADATA_FILE);
        if (! Storage::disk('local')->exists($metadataPath)) {
            return null;
        }
        try {
            $metadata = json_decode(Storage::disk('local')->get($metadataPath), true);

            return $metadata;
        } catch (Exception $e) {
            Log::error("Error reading cache metadata: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * Parse XMLTV datetime format
     */
    private function parseXmltvDateTime(string $datetime): ?Carbon
    {
        try {
            if (preg_match('/(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})\s*([+-]\d{4})?/', $datetime, $matches)) {
                $year = $matches[1];
                $month = $matches[2];
                $day = $matches[3];
                $hour = $matches[4];
                $minute = $matches[5];
                $second = $matches[6];
                $timezone = $matches[7] ?? '+0000';

                $dateString = "{$year}-{$month}-{$day} {$hour}:{$minute}:{$second}";

                if (preg_match('/([+-])(\d{2})(\d{2})/', $timezone, $tzMatches)) {
                    $tzString = $tzMatches[1].$tzMatches[2].':'.$tzMatches[3];
                    $dateString .= ' '.$tzString;
                }

                return Carbon::parse($dateString);
            }
        } catch (Exception $e) {
            Log::warning("Failed to parse XMLTV datetime: {$datetime}");
        }

        return null;
    }

    /**
     * Get the cache file path for a playlist
     */
    public static function getPlaylistEpgCachePath(
        Playlist|MergedPlaylist|CustomPlaylist|PlaylistAlias $playlist,
        bool $compressed = false
    ): string {
        // Need to ensure unique filenames across all playlist types
        $id = $playlist->getTable().'-'.$playlist->id;
        $filename = "$id-epg";
        if ($compressed) {
            $filename .= '.xml.gz';
        } else {
            $filename .= '.xml';
        }

        return 'playlist-epg-files/'.$filename;
    }

    /**
     * Clear cache for a specific playlist
     */
    public static function clearPlaylistEpgCacheFile($playlist): bool
    {
        $disk = Storage::disk('local');
        $xmlPath = self::getPlaylistEpgCachePath($playlist, false);
        $gzPath = self::getPlaylistEpgCachePath($playlist, true);

        try {
            $cleared = false;
            if ($disk->exists($xmlPath)) {
                $disk->delete($xmlPath);
                $cleared = true;
            }
            if ($disk->exists($gzPath)) {
                $disk->delete($gzPath);
                $cleared = true;
            }
            Log::debug("Cleared EPG file cache for playlist {$playlist->name}");

            return $cleared;
        } catch (Exception $e) {
            Log::error("Failed to clear playlist EPG cache: {$e->getMessage()}");
        }

        return false;
    }

    /**
     * Clear EPG file caches for all playlist types (source, custom, merged, aliases)
     * that contain any of the given channel IDs.
     *
     * Executes 4 DB queries then one bulk Storage::delete - no model hydration, no N+1.
     */
    public static function clearForChannelIds(array $channelIds): void
    {
        if (empty($channelIds)) {
            return;
        }

        // Source playlist IDs from the channels table
        $sourceIds = DB::table('channels')
            ->whereIn('id', $channelIds)
            ->whereNotNull('playlist_id')
            ->distinct()
            ->pluck('playlist_id')
            ->all();

        // Custom playlist IDs via the channel_custom_playlist pivot
        $customIds = DB::table('channel_custom_playlist')
            ->whereIn('channel_id', $channelIds)
            ->distinct()
            ->pluck('custom_playlist_id')
            ->all();

        // Merged playlist IDs via the merged_playlist_playlist pivot
        $mergedIds = $sourceIds
            ? DB::table('merged_playlist_playlist')
                ->whereIn('playlist_id', $sourceIds)
                ->distinct()
                ->pluck('merged_playlist_id')
                ->all()
            : [];

        // Alias IDs for any affected source or custom playlist
        $aliasIds = ($sourceIds || $customIds)
            ? DB::table('playlist_aliases')
                ->where(function ($q) use ($sourceIds, $customIds): void {
                    if ($sourceIds) {
                        $q->whereIn('playlist_id', $sourceIds);
                    }
                    if ($customIds) {
                        $q->orWhereIn('custom_playlist_id', $customIds);
                    }
                })
                ->distinct()
                ->pluck('id')
                ->all()
            : [];

        self::bulkDeleteCacheFiles([
            'playlists' => $sourceIds,
            'custom_playlists' => $customIds,
            'merged_playlists' => $mergedIds,
            'playlist_aliases' => $aliasIds,
        ]);
    }

    /**
     * Clear EPG file caches for all playlist types affected by a group channel recount.
     *
     * Uses the group's known playlist_id directly (skips loading channel IDs into PHP)
     * and finds custom playlists via a JOIN on group_id.
     */
    public static function clearForGroup(int $groupId, int $playlistId): void
    {
        // Custom playlists that contain channels from this group
        $customIds = DB::table('channel_custom_playlist as ccp')
            ->join('channels as c', 'c.id', '=', 'ccp.channel_id')
            ->where('c.group_id', $groupId)
            ->distinct()
            ->pluck('ccp.custom_playlist_id')
            ->all();

        // Merged playlists that include this source playlist
        $mergedIds = DB::table('merged_playlist_playlist')
            ->where('playlist_id', $playlistId)
            ->distinct()
            ->pluck('merged_playlist_id')
            ->all();

        // Aliases pointing to this source playlist or any affected custom playlist
        $aliasIds = DB::table('playlist_aliases')
            ->where(function ($q) use ($playlistId, $customIds): void {
                $q->where('playlist_id', $playlistId);
                if ($customIds) {
                    $q->orWhereIn('custom_playlist_id', $customIds);
                }
            })
            ->distinct()
            ->pluck('id')
            ->all();

        self::bulkDeleteCacheFiles([
            'playlists' => [$playlistId],
            'custom_playlists' => $customIds,
            'merged_playlists' => $mergedIds,
            'playlist_aliases' => $aliasIds,
        ]);
    }

    /**
     * Clear EPG file cache for a custom playlist and any playlist aliases pointing to it.
     * Used when pivot channel_number values change (custom playlist channel recount).
     */
    public static function clearForCustomPlaylistId(int $customPlaylistId): void
    {
        $aliasIds = DB::table('playlist_aliases')
            ->where('custom_playlist_id', $customPlaylistId)
            ->pluck('id')
            ->all();

        self::bulkDeleteCacheFiles([
            'custom_playlists' => [$customPlaylistId],
            'playlist_aliases' => $aliasIds,
        ]);
    }

    /**
     * Build EPG cache file paths from a table → IDs map and delete them in one Storage call.
     */
    private static function bulkDeleteCacheFiles(array $tableIdMap): void
    {
        $paths = [];
        foreach ($tableIdMap as $table => $ids) {
            foreach ($ids as $id) {
                $paths[] = "playlist-epg-files/{$table}-{$id}-epg.xml";
                $paths[] = "playlist-epg-files/{$table}-{$id}-epg.xml.gz";
            }
        }
        if ($paths) {
            Storage::disk('local')->delete($paths);
        }
    }

    public static function getEpgTableAction()
    {
        return Action::make('Download EPG')
            ->label('Download EPG')
            ->icon('heroicon-o-arrow-down-tray')
            ->modalHeading('Download EPG')
            ->modalIcon('heroicon-o-arrow-down-tray')
            ->modalDescription('Select the EPG format to download and your download will begin immediately.')
            ->modalWidth('md')
            ->schema(function ($record) {
                $urls = PlaylistFacade::getUrls($record);

                return [
                    Select::make('format')
                        ->label('EPG Format')
                        ->options([
                            'uncompressed' => 'Uncompressed EPG',
                            'compressed' => 'Gzip Compressed EPG',
                        ])
                        ->default('uncompressed')
                        ->required()
                        ->reactive()
                        ->afterStateUpdated(function ($state, $set) use ($urls) {
                            if ($state === 'uncompressed') {
                                $set('download_url', $urls['epg']);
                            } else {
                                $set('download_url', $urls['epg_zip']);
                            }
                        })->hintAction(
                            Action::make('clear_cache')
                                ->icon('heroicon-m-trash')
                                ->label('Clear Cache')
                                ->requiresConfirmation()
                                ->color('warning')
                                ->modalIcon('heroicon-m-trash')
                                ->modalHeading('Clear Playlist EPG File Cache')
                                ->modalDescription('Clear the EPG file cache for this playlist? It will be automatically regenerated on the next download.')
                                ->action(function ($record, $state) {
                                    $status = self::clearPlaylistEpgCacheFile($record);
                                    if ($status) {
                                        Notification::make()
                                            ->title('Cache Cleared')
                                            ->success()
                                            ->send();
                                    } else {
                                        Notification::make()
                                            ->title('File not yet cached')
                                            ->warning()
                                            ->send();
                                    }
                                })
                        ),
                    TextInput::make('download_url')
                        ->label('Download URL')
                        ->default($urls['epg'])
                        ->required()
                        ->disabled()
                        ->dehydrated(fn (): bool => true),
                ];
            })
            ->action(function (array $data): void {
                $url = $data['download_url'] ?? '';
                if ($url) {
                    redirect($url);
                } else {
                    Notification::make()
                        ->title('Download URL not available')
                        ->danger()
                        ->send();
                }
            })
            ->modalSubmitActionLabel('Download EPG');
    }

    public static function getEpgPlaylistAction()
    {
        return Action::make('Download EPG')
            ->label('Download EPG')
            ->icon('heroicon-o-arrow-down-tray')
            ->modalHeading('Download EPG')
            ->modalIcon('heroicon-o-arrow-down-tray')
            ->modalDescription('Select the EPG format to download and your download will begin immediately.')
            ->modalWidth('md')
            ->schema(function ($record) {
                $urls = PlaylistFacade::getUrls($record);

                return [
                    Select::make('format')
                        ->label('EPG Format')
                        ->options([
                            'uncompressed' => 'Uncompressed EPG',
                            'compressed' => 'Gzip Compressed EPG',
                        ])
                        ->default('uncompressed')
                        ->required()
                        ->reactive()
                        ->afterStateUpdated(function ($state, $set) use ($urls) {
                            if ($state === 'uncompressed') {
                                $set('download_url', $urls['epg']);
                            } else {
                                $set('download_url', $urls['epg_zip']);
                            }
                        })
                        ->hintAction(
                            Action::make('clear_cache')
                                ->icon('heroicon-m-trash')
                                ->label('Clear Cache')
                                ->requiresConfirmation()
                                ->color('warning')
                                ->modalIcon('heroicon-m-trash')
                                ->modalHeading('Clear Playlist EPG File Cache')
                                ->modalDescription('Clear the EPG file cache for this playlist? It will be automatically regenerated on the next download.')
                                ->action(function ($record, $state) {
                                    $status = self::clearPlaylistEpgCacheFile($record);
                                    if ($status) {
                                        Notification::make()
                                            ->title('Cache Cleared')
                                            ->success()
                                            ->send();
                                    } else {
                                        Notification::make()
                                            ->title('File not yet cached')
                                            ->warning()
                                            ->send();
                                    }
                                })
                        ),
                    TextInput::make('download_url')
                        ->label('Download URL')
                        ->default($urls['epg'])
                        ->required()
                        ->disabled()
                        ->dehydrated(fn (): bool => true),
                ];
            })
            ->action(function (array $data): void {
                $url = $data['download_url'] ?? '';
                if ($url) {
                    redirect($url);
                } else {
                    Notification::make()
                        ->title('Download URL not available')
                        ->danger()
                        ->send();
                }
            })
            ->modalSubmitActionLabel('Download EPG');
    }

    /**
     * Populate the epg_programmes DB table from the JSONL cache for DVR-enabled playlists.
     *
     * Public so it can be called directly (e.g. from an artisan command) when a DVR
     * mapping is added after an EPG was last cached.
     */
    public function populateDvrProgrammes(Epg $epg): void
    {
        // Resolve every Playlist/CustomPlaylist/MergedPlaylist that has channels
        // mapped to this EPG, then check whether any of them has an enabled DVR
        // setting. Covers all three DvrSetting owner types (a plain playlist_id
        // lookup would miss DVR settings owned by a CustomPlaylist or MergedPlaylist).
        $playlistIds = [];
        $customPlaylistIds = [];
        $mergedPlaylistIds = [];

        foreach ($epg->getAllPlaylists() as $consumer) {
            match (true) {
                $consumer instanceof Playlist => $playlistIds[] = $consumer->id,
                $consumer instanceof CustomPlaylist => $customPlaylistIds[] = $consumer->id,
                $consumer instanceof MergedPlaylist => $mergedPlaylistIds[] = $consumer->id,
                default => null,
            };
        }

        $hasDvrSetting = DvrSetting::where('enabled', true)
            ->where(function ($query) use ($playlistIds, $customPlaylistIds, $mergedPlaylistIds): void {
                if (! empty($playlistIds)) {
                    $query->orWhereIn('playlist_id', $playlistIds);
                }
                if (! empty($customPlaylistIds)) {
                    $query->orWhereIn('custom_playlist_id', $customPlaylistIds);
                }
                if (! empty($mergedPlaylistIds)) {
                    $query->orWhereIn('merged_playlist_id', $mergedPlaylistIds);
                }
            })
            ->exists();

        if (! $hasDvrSetting) {
            return;
        }

        Log::debug("Populating epg_programmes for EPG {$epg->name} (id={$epg->id})");

        // Delete stale rows for this EPG so we do a clean refresh
        EpgProgramme::where('epg_id', $epg->id)->delete();

        $now = now();
        $from = $now->copy()->subDay()->startOfDay();
        $to = $now->copy()->addDays(30)->endOfDay();

        $batch = [];
        $inserted = 0;
        $skipped = 0;

        $store = $this->programmeStore($epg);
        if ($store !== null) {
            foreach ($store->readForDvr($from->format('Y-m-d'), $to->format('Y-m-d')) as [$channelId, $programme]) {
                $this->bufferDvrProgramme($epg, $channelId, $programme, $batch, $inserted, $skipped);
            }
        } else {
            // Legacy JSONL scan: v1 caches, or v2 caches written before the SQLite switch.
            $current = $from->copy();
            while ($current->lte($to)) {
                $date = $current->format('Y-m-d');
                $filePath = $this->getActiveCacheFilePath($epg, "programmes-{$date}.jsonl");

                if (! Storage::disk('local')->exists($filePath)) {
                    $current->addDay();

                    continue;
                }

                $handle = fopen(Storage::disk('local')->path($filePath), 'r');
                if (! $handle) {
                    $current->addDay();

                    continue;
                }

                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if (empty($line)) {
                        continue;
                    }

                    $record = json_decode($line, true);
                    if (! $record || ! isset($record['channel'], $record['programme'])) {
                        continue;
                    }

                    $this->bufferDvrProgramme($epg, (string) $record['channel'], $record['programme'], $batch, $inserted, $skipped);
                }

                fclose($handle);
                $current->addDay();
            }
        }

        // Flush remaining
        if (! empty($batch)) {
            try {
                EpgProgramme::insert($batch);
                $inserted += count($batch);
            } catch (Exception $e) {
                Log::error("DVR programme final batch insert failed (EPG {$epg->id}): {$e->getMessage()}");
                $skipped += count($batch);
            }
        }

        Log::debug("EPG programmes populated for DVR: {$inserted} inserted, {$skipped} skipped", [
            'epg_id' => $epg->id,
            'epg_name' => $epg->name,
        ]);
    }

    /**
     * Normalize one cached programme into an `epg_programmes` row and append it
     * to $batch, flushing to the DB every 500 rows. Shared by the SQLite and
     * legacy-JSONL code paths in {@see populateDvrProgrammes()}.
     *
     * @param  array<string, mixed>  $p  programme payload (canonical shape)
     * @param  list<array<string, mixed>>  $batch
     */
    private function bufferDvrProgramme(Epg $epg, string $epgChannelId, array $p, array &$batch, int &$inserted, int &$skipped): void
    {
        try {
            $appTz = config('app.timezone', 'UTC');
            $startTime = isset($p['start']) ? Carbon::parse($p['start'])->setTimezone($appTz) : null;
            $endTime = isset($p['stop']) ? Carbon::parse($p['stop'])->setTimezone($appTz) : null;

            if (! $startTime || ! $endTime) {
                $skipped++;

                return;
            }

            // Parse season/episode from all episode-num entries in the programme.
            [$season, $episode] = $this->parseEpisodeNumbers($p);

            // Normalize provider-quirky free-text fields. Some feeds smuggle a
            // "ᴺᵉʷ" superscript marker into the title and prefix descriptions
            // with "S## E### Subtitle\nDescription" instead of using proper
            // <new/> / <episode-num> / <subtitle> elements.
            $titleNorm = EpgProgrammeNormalizer::normalizeTitle($p['title'] ?? null);
            $subtitle = $this->nullableString($p['subtitle'] ?? null, 500);
            $description = $this->nullableString($p['desc'] ?? null);
            if ($subtitle === null || $season === null || $episode === null) {
                $extracted = EpgProgrammeNormalizer::extractSeasonEpisodeFromDescription($description);
                if ($extracted['season'] !== null || $extracted['episode'] !== null) {
                    $season ??= $extracted['season'];
                    $episode ??= $extracted['episode'];
                    if ($subtitle === null && $extracted['subtitle'] !== null) {
                        $subtitle = mb_substr($extracted['subtitle'], 0, 500);
                    }
                    $description = $extracted['description'];
                }
            }

            $batch[] = [
                'epg_id' => $epg->id,
                'epg_channel_id' => mb_substr($epgChannelId, 0, 500),
                'title' => mb_substr($titleNorm['title'], 0, 500),
                'subtitle' => $subtitle,
                'description' => $description,
                'category' => $this->nullableString($p['category'] ?? null, 255),
                // Raw insert bypasses Eloquent casts. The `start_time`/`end_time`
                // columns are cast as `datetime` which Eloquent interprets in
                // `app.timezone`. We must therefore write the wall-clock of
                // `app.timezone` (NOT UTC) so round-tripping yields correct UTC.
                'start_time' => $startTime->copy()->tz(config('app.timezone'))->toDateTimeString(),
                'end_time' => $endTime->copy()->tz(config('app.timezone'))->toDateTimeString(),
                'episode_num' => $this->nullableString($p['episode_num'] ?? null, 255),
                'season' => $season,
                'episode' => $episode,
                'is_new' => (bool) ($p['new'] ?? false) || $titleNorm['isNew'],
                'previously_shown' => (bool) ($p['previously_shown'] ?? false),
                'premiere' => (bool) ($p['premiere'] ?? false),
                'icon' => $this->nullableString($p['icon'] ?? null, 500),
                'rating' => $this->nullableString($p['rating'] ?? null, 50),
                'created_at' => now()->toDateTimeString(),
                'updated_at' => now()->toDateTimeString(),
            ];
        } catch (Exception $e) {
            Log::warning("DVR programme parse error (EPG {$epg->id}): {$e->getMessage()}");
            $skipped++;
        }

        // Flush outside the per-record try-catch so a failed insert doesn't
        // leave the batch dirty and cascade failures.
        if (count($batch) >= 500) {
            try {
                EpgProgramme::insert($batch);
                $inserted += count($batch);
            } catch (Exception $e) {
                Log::error("DVR programme batch insert failed (EPG {$epg->id}): {$e->getMessage()}");
                $skipped += count($batch);
            }
            $batch = [];
        }
    }

    /**
     * Return null for blank/empty strings, otherwise truncate to the column limit.
     */
    private function nullableString(?string $value, ?int $maxLength = null): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $maxLength !== null ? mb_substr($value, 0, $maxLength) : $value;
    }

    /**
     * Parse season and episode integers from XMLTV episode-num data.
     *
     * Priority order:
     *   1. Explicit `system="xmltv_ns"` entry — 0-indexed dot notation ("S.E.P"),
     *      converted to 1-indexed integers (e.g. "1.2." → season 2, episode 3).
     *   2. Explicit `system="onscreen"` entry — 1-indexed SxxExx literal
     *      (e.g. "S02E05" → season 2, episode 5).
     *   3. Heuristic on the raw `episode_num` string when no explicit system tag:
     *      dots → treated as xmltv_ns (0-indexed); SxxExx → treated as onscreen (1-indexed).
     *
     * @param  array<string, mixed>  $programme  Parsed programme payload from parseProgrammesStream
     * @return array{0: int|null, 1: int|null} [season, episode]
     */
    protected function parseEpisodeNumbers(array $programme): array
    {
        return EpisodeNumberParser::fromProgramme($programme);
    }
}
