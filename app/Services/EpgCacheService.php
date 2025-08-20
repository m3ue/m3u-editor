<?php

namespace App\Services;

use App\Facades\PlaylistUrlFacade;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\ExtJsonDecoder;
use Filament\Forms;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Tables;
use XMLReader;

class EpgCacheService
{
    private const CACHE_VERSION = 'v1';
    private const CHANNELS_FILE = 'channels.json';
    private const METADATA_FILE = 'metadata.json';
    private const MAX_PROGRAMMES = 10000000; // Safety limit

    /**
     * Get the cache directory path for an EPG
     */
    private function getCacheDir(Epg $epg): string
    {
        return "epg-cache/{$epg->uuid}/" . self::CACHE_VERSION;
    }

    /**
     * Get cache file path
     */
    private function getCacheFilePath(Epg $epg, string $filename): string
    {
        return $this->getCacheDir($epg) . '/' . $filename;
    }

    /**
     * Check if cache is valid
     */
    public function isCacheValid(Epg $epg): bool
    {
        $metadataPath = $this->getCacheFilePath($epg, self::METADATA_FILE);

        if (!Storage::disk('local')->exists($metadataPath)) {
            return false;
        }

        try {
            // Check if EPG file has been modified since cache was created
            $epgFilePath = Storage::disk('local')->path($epg->file_path);
            if (!file_exists($epgFilePath)) {
                return false;
            }

            // Use json_decode for metadata parsing since it will be a small file
            $metadata = json_decode(Storage::disk('local')->get($metadataPath), true);

            $epgFileModified = filemtime($epgFilePath);
            $cacheCreated = $metadata['cache_created'] ?? 0;

            return $epgFileModified <= $cacheCreated;
        } catch (\Exception $e) {
            Log::warning("Invalid cache metadata for EPG {$epg->uuid}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Cache EPG data from XML file
     */
    public function cacheEpgData(Epg $epg): bool
    {
        $epgFilePath = Storage::disk('local')->path($epg->file_path);
        if (!file_exists($epgFilePath)) {
            Log::error("EPG file not found: {$epgFilePath}");
            return false;
        }
        try {
            Log::debug("Starting EPG cache generation for {$epg->name}");
            set_time_limit(600); // 10 minutes

            // Get the channel count for progress tracking
            $totalChannels = $epg->channels()->count();

            // Start by clearing existing cache
            $this->clearCache($epg);
            $cacheDir = $this->getCacheDir($epg);
            Storage::disk('local')->makeDirectory($cacheDir);

            // Parse and save channels using streaming
            Log::debug("Parsing and saving channels for {$epg->name}");
            $channelCount = $this->parseAndSaveChannels($epg, $epgFilePath, $totalChannels);
            Log::debug("Processed {$channelCount} channels");

            // Parse and save programmes using streaming by date
            Log::debug("Parsing and saving programmes for {$epg->name}");
            $programmeStats = $this->parseAndSaveProgrammes($epg, $epgFilePath, $totalChannels);
            Log::debug("Processed {$programmeStats['total']} programmes across {$programmeStats['date_count']} dates");

            // Save metadata
            $metadata = [
                'epg_uuid' => $epg->uuid,
                'epg_name' => $epg->name,
                'cache_created' => time(),
                'cache_version' => self::CACHE_VERSION,
                'total_channels' => $channelCount,
                'total_programmes' => $programmeStats['total'],
                'programme_date_range' => $programmeStats['date_range'],
            ];

            Storage::disk('local')->put(
                $this->getCacheFilePath($epg, self::METADATA_FILE),
                json_encode($metadata, JSON_PRETTY_PRINT)
            );

            // Flag EPG as cached
            $epg->update([
                'is_cached' => true,
                'cache_meta' => $metadata,
                'cache_progress' => 100
            ]);

            Log::debug("EPG cache generated successfully", $metadata);
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to cache EPG data for {$epg->name}: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Parse and save channels
     */
    private function parseAndSaveChannels(Epg $epg, string $filePath, int $totalChannels): int
    {
        $channelCount = 0;
        $batchSize = 1000; // Process channels in batches
        $channelBatch = [];

        foreach ($this->parseChannelsStream($filePath) as $channelId => $channel) {
            $channelBatch[$channelId] = $channel;
            $channelCount++;

            // Save in batches to manage memory
            if (count($channelBatch) >= $batchSize) {
                $this->saveChannelBatch($epg, $channelBatch, $channelCount <= $batchSize);
                $channelBatch = [];

                // Update progress
                // Max is 20% for channels since programmes are more intensive
                $progress = $totalChannels > 0
                    ? min(20, round(($channelCount / $totalChannels) * 20))
                    : 20;
                $epg->update(['cache_progress' => $progress]);
            }
        }

        // Save remaining channels
        if (!empty($channelBatch)) {
            $this->saveChannelBatch($epg, $channelBatch, $channelCount <= $batchSize);
        }

        return $channelCount;
    }

    /**
     * Parse and save programmes using direct file append
     */
    private function parseAndSaveProgrammes(Epg $epg, string $filePath, int $totalChannels): array
    {
        $totalProgrammes = 0;
        $dateRangeTracker = ['min_date' => null, 'max_date' => null];
        $processedDates = [];
        $openFiles = []; // Keep track of open file handles
        $programmeCount = $this->countProgrammesInFile($filePath);

        foreach ($this->parseProgrammesStream($filePath) as $programme) {
            $date = Carbon::parse($programme['start'])->format('Y-m-d');

            // Track date range
            if ($dateRangeTracker['min_date'] === null || $date < $dateRangeTracker['min_date']) {
                $dateRangeTracker['min_date'] = $date;
            }
            if ($dateRangeTracker['max_date'] === null || $date > $dateRangeTracker['max_date']) {
                $dateRangeTracker['max_date'] = $date;
            }

            // Use direct file append with minimal memory footprint
            $this->directAppendProgramme($epg, $date, $programme['channel'], $programme, $openFiles);
            $totalProgrammes++;
            $processedDates[$date] = true;

            // Force garbage collection every 50 programmes (more frequent)
            if ($totalProgrammes % 50 === 0) {
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }

                // Close file handles periodically to prevent too many open files
                if (count($openFiles) > 10) {
                    foreach ($openFiles as $handle) {
                        if (is_resource($handle)) {
                            fclose($handle);
                        }
                    }
                    $openFiles = [];
                }

                // Update progress
                $progress = $totalChannels > 0
                    ? min(99, 20 + round(($totalProgrammes / $programmeCount) * 80))
                    : 99;
                $epg->update(['cache_progress' => $progress]);
            }
        }

        // Close any remaining file handles
        foreach ($openFiles as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        return [
            'total' => $totalProgrammes,
            'date_count' => count($processedDates),
            'date_range' => $dateRangeTracker,
        ];
    }

    /**
     * Count programmes in file for progress tracking
     */
    private function countProgrammesInFile(string $filePath): int
    {
        $count = 0;

        try {
            // Open compressed file for reading
            $handle = gzopen($filePath, 'r');
            if (!$handle) {
                Log::warning("Could not open file for programme counting: {$filePath}");
                return 0;
            }

            // Read file in chunks and count programme occurrences
            while (!gzeof($handle)) {
                $chunk = gzread($handle, 8192); // 8KB chunks
                if ($chunk === false) {
                    break;
                }

                // Count occurrences of '<programme' in this chunk
                $count += substr_count($chunk, '<programme');

                // Apply safety limit
                if ($count > self::MAX_PROGRAMMES) {
                    Log::warning("Programme counting limit reached at {$count}");
                    $count = self::MAX_PROGRAMMES;
                    break;
                }
            }

            gzclose($handle);
        } catch (\Exception $e) {
            Log::warning("Error counting programmes: {$e->getMessage()}");
            return 0;
        }

        return $count;
    }

    /**
     * Direct append programme - JSONL format for efficiency
     */
    private function directAppendProgramme(Epg $epg, string $date, string $channelId, array $programme, array &$openFiles): void
    {
        $filename = "programmes-{$date}.jsonl"; // Use JSONL format for line-by-line append
        $programmesPath = $this->getCacheFilePath($epg, $filename);
        $fullPath = Storage::disk('local')->path($programmesPath);

        // Ensure directory exists
        $dir = dirname($fullPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Prepare the programme record with channel info
        $record = [
            'channel' => $channelId,
            'programme' => $programme
        ];

        // Append to file using direct file operations (most memory efficient)
        $line = json_encode($record, JSON_UNESCAPED_UNICODE) . "\n";

        try {
            // Use file_put_contents with append flag - minimal memory usage
            file_put_contents($fullPath, $line, FILE_APPEND | LOCK_EX);
        } catch (\Exception $e) {
            Log::error("Failed to append programme to {$filename}: {$e->getMessage()}");
        }
    }

    /**
     * Stream parse channels from EPG file using generators
     */
    private function parseChannelsStream(string $filePath): \Generator
    {
        $channelReader = new XMLReader();
        $channelReader->open('compress.zlib://' . $filePath);

        while (@$channelReader->read()) {
            if ($channelReader->nodeType == XMLReader::ELEMENT && $channelReader->name === 'channel') {
                $channelId = trim($channelReader->getAttribute('id') ?: '');
                $innerXML = $channelReader->readOuterXml();
                $innerReader = new XMLReader();
                $innerReader->xml($innerXML);

                $channel = [
                    'id' => $channelId,
                    'display_name' => '',
                    'icon' => '',
                    'lang' => 'en'
                ];

                while (@$innerReader->read()) {
                    if ($innerReader->nodeType == XMLReader::ELEMENT) {
                        switch ($innerReader->name) {
                            case 'display-name':
                                if (!$channel['display_name']) {
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
                    yield $channelId => $channel;
                }
            }
        }
        $channelReader->close();
    }

    /**
     * Stream parse programmes from EPG file using generators
     */
    private function parseProgrammesStream(string $filePath): \Generator
    {
        $programReader = new XMLReader();
        $programReader->open('compress.zlib://' . $filePath);
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

                if (!$channelId || !$start) {
                    continue;
                }

                $startDateTime = $this->parseXmltvDateTime($start);
                $stopDateTime = $stop ? $this->parseXmltvDateTime($stop) : null;

                if (!$startDateTime) {
                    continue;
                }

                $innerXML = $programReader->readOuterXml();
                $innerReader = new XMLReader();
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
                    'rating' => '',
                    'icon' => '',
                    'new' => false,
                ];

                while (@$innerReader->read()) {
                    if ($innerReader->nodeType == XMLReader::ELEMENT) {
                        switch ($innerReader->name) {
                            case 'title':
                                $programme['title'] = trim($innerReader->readString() ?: '');
                                break;
                            case 'sub-title':
                                $programme['subtitle'] = trim($innerReader->readString() ?: '');
                                break;
                            case 'desc':
                                $programme['desc'] = trim($innerReader->readString() ?: '');
                                break;
                            case 'category':
                                if (!$programme['category']) {
                                    $programme['category'] = trim($innerReader->readString() ?: '');
                                }
                                break;
                            case 'icon':
                                $programme['icon'] = trim($innerReader->getAttribute('src') ?: '');
                                break;
                            case 'premiere':
                                $programme['new'] = true;
                                break;
                            case 'episode-num':
                                $programme['episode_num'] = trim($innerReader->readString() ?: '');
                                break;
                            case 'rating':
                                // Read rating value
                                while (@$innerReader->read()) {
                                    if ($innerReader->nodeType == XMLReader::ELEMENT && $innerReader->name === 'value') {
                                        $programme['rating'] = trim($innerReader->readString() ?: '');
                                        break;
                                    } elseif ($innerReader->nodeType == XMLReader::END_ELEMENT && $innerReader->name === 'rating') {
                                        break;
                                    }
                                }
                                break;
                        }
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
     * Save channel batch to file
     */
    private function saveChannelBatch(Epg $epg, array $channelBatch, bool $isFirst): void
    {
        $channelsPath = $this->getCacheFilePath($epg, self::CHANNELS_FILE);

        if ($isFirst) {
            // First batch - create new file
            Storage::disk('local')->put(
                $channelsPath,
                json_encode($channelBatch, JSON_UNESCAPED_UNICODE)
            );
        } else {
            // Subsequent batches - merge with existing data using JsonMachine
            $existingData = [];

            if (Storage::disk('local')->exists($channelsPath)) {
                try {
                    $existingStream = Items::fromFile(
                        Storage::disk('local')->path($channelsPath),
                        ['decoder' => new ExtJsonDecoder(true)]
                    );

                    // Convert existing data to array (should be relatively small for channels)
                    foreach ($existingStream as $channelId => $channel) {
                        $existingData[$channelId] = $channel;
                    }
                } catch (\Exception $e) {
                    Log::warning("Could not read existing channel data, creating new file: {$e->getMessage()}");
                    $existingData = [];
                }
            }

            $mergedData = array_merge($existingData, $channelBatch);
            Storage::disk('local')->put(
                $channelsPath,
                json_encode($mergedData, JSON_UNESCAPED_UNICODE)
            );
        }
    }

    /**
     * Get cached channels
     */
    public function getCachedChannels(Epg $epg, int $page = 1, int $perPage = 50): array
    {
        $channelsPath = $this->getCacheFilePath($epg, self::CHANNELS_FILE);

        if (!Storage::disk('local')->exists($channelsPath)) {
            return [
                'channels' => [],
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total_channels' => 0,
                    'returned_channels' => 0,
                    'has_more' => false,
                    'next_page' => null,
                ]
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
                ]
            ];
        } catch (\Exception $e) {
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
                ]
            ];
        }
    }

    /**
     * Get cached programmes for a specific date and channels
     */
    public function getCachedProgrammes(Epg $epg, string $date, array $channelIds = []): array
    {
        $programmesPath = $this->getCacheFilePath($epg, "programmes-{$date}.jsonl");

        if (!Storage::disk('local')->exists($programmesPath)) {
            return [];
        }

        try {
            $programmes = [];
            $fullPath = Storage::disk('local')->path($programmesPath);

            // Read JSONL file line by line
            if (($handle = fopen($fullPath, 'r')) !== false) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if (empty($line)) continue;

                    try {
                        $record = json_decode($line, true);
                        if (!$record || !isset($record['channel']) || !isset($record['programme'])) {
                            continue;
                        }

                        $channelId = $record['channel'];
                        $programme = $record['programme'];

                        // Filter by channel IDs if provided
                        if (!empty($channelIds) && !in_array($channelId, $channelIds)) {
                            continue;
                        }

                        if (!isset($programmes[$channelId])) {
                            $programmes[$channelId] = [];
                        }
                        $programmes[$channelId][] = $programme;
                    } catch (\Exception $lineError) {
                        Log::warning("Failed to parse programme line: {$lineError->getMessage()}");
                        continue;
                    }
                }
                fclose($handle);
            }

            return $programmes;
        } catch (\Exception $e) {
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
                if (!isset($allProgrammes[$channelId])) {
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
    private function streamCachedProgrammesForDate(Epg $epg, string $date, array $channelIds = []): \Generator
    {
        $programmesPath = $this->getCacheFilePath($epg, "programmes-{$date}.jsonl");
        if (!Storage::disk('local')->exists($programmesPath)) {
            return;
        }

        try {
            $channelProgrammes = [];
            $fullPath = Storage::disk('local')->path($programmesPath);

            // Read JSONL file line by line
            if (($handle = fopen($fullPath, 'r')) !== false) {
                while (($line = fgets($handle)) !== false) {
                    $line = trim($line);
                    if (empty($line)) continue;

                    try {
                        $record = json_decode($line, true);
                        if (!$record || !isset($record['channel']) || !isset($record['programme'])) {
                            continue;
                        }

                        $channelId = $record['channel'];
                        $programme = $record['programme'];

                        // Filter by channel IDs if provided
                        if (!empty($channelIds) && !in_array($channelId, $channelIds)) {
                            continue;
                        }

                        if (!isset($channelProgrammes[$channelId])) {
                            $channelProgrammes[$channelId] = [];
                        }
                        $channelProgrammes[$channelId][] = $programme;
                    } catch (\Exception $lineError) {
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
        } catch (\Exception $e) {
            Log::error("Error streaming cached programmes for date {$date}: {$e->getMessage()}");
        }
    }

    /**
     * Get cache metadata
     */
    public function getCacheMetadata(Epg $epg): ?array
    {
        $metadataPath = $this->getCacheFilePath($epg, self::METADATA_FILE);
        if (!Storage::disk('local')->exists($metadataPath)) {
            return null;
        }
        try {
            $metadata = json_decode(Storage::disk('local')->get($metadataPath), true);
            return $metadata;
        } catch (\Exception $e) {
            Log::error("Error reading cache metadata: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Clear cache for an EPG
     */
    public function clearCache(Epg $epg): bool
    {
        // Get the cache directory
        $cacheDir = $this->getCacheDir($epg);
        try {
            // Flag EPG as not cached
            $epg->update([
                'is_cached' => false,
                'cache_meta' => null,
                'cache_progress' => 0
            ]);

            // Delete cache directory
            Storage::disk('local')->deleteDirectory($cacheDir);

            // Log cache clearing
            Log::debug("Cleared cache for EPG {$epg->name}");
            return true;
        } catch (\Exception $e) {
            Log::error("Failed to clear cache for EPG {$epg->name}: {$e->getMessage()}");
            return false;
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
                    $tzString = $tzMatches[1] . $tzMatches[2] . ':' . $tzMatches[3];
                    $dateString .= ' ' . $tzString;
                }

                return Carbon::parse($dateString);
            }
        } catch (\Exception $e) {
            Log::warning("Failed to parse XMLTV datetime: {$datetime}");
        }

        return null;
    }

    /**
     * Get the cache file path for a playlist
     */
    static function getPlaylistEpgCachePath(
        Playlist|MergedPlaylist|CustomPlaylist $playlist,
        bool $compressed = false
    ): string {
        // Playlist UUID can change, so use ID for cache file names
        $filename = "playlist-{$playlist->id}-epg";
        if ($compressed) {
            $filename .= '.xml.gz';
        } else {
            $filename .= '.xml';
        }
        return 'playlist-epg-files/' . $filename;
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
            return $cleared;
        } catch (\Exception $e) {
            Log::error("Failed to clear playlist EPG cache: {$e->getMessage()}");
        }

        return false;
    }

    public static function getEpgTableAction()
    {
        return Tables\Actions\Action::make('Download EPG')
            ->label('Download EPG')
            ->icon('heroicon-o-arrow-down-tray')
            ->modalHeading('Download EPG')
            ->modalIcon('heroicon-o-arrow-down-tray')
            ->modalDescription('Select the EPG format to download and your download will begin immediately.')
            ->modalWidth('md')
            ->form(function ($record) {
                $urls = PlaylistUrlFacade::getUrls($record);
                return [
                    Forms\Components\Select::make('format')
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
                            Forms\Components\Actions\Action::make('clear_cache')
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
                    Forms\Components\TextInput::make('download_url')
                        ->label('Download URL')
                        ->default($urls['epg'])
                        ->required()
                        ->disabled()
                        ->dehydrated(fn(): bool => true),
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
        return Actions\Action::make('Download EPG')
            ->label('Download EPG')
            ->icon('heroicon-o-arrow-down-tray')
            ->modalHeading('Download EPG')
            ->modalIcon('heroicon-o-arrow-down-tray')
            ->modalDescription('Select the EPG format to download and your download will begin immediately.')
            ->modalWidth('md')
            ->form(function ($record) {
                $urls = PlaylistUrlFacade::getUrls($record);
                return [
                    Forms\Components\Select::make('format')
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
                            Forms\Components\Actions\Action::make('clear_cache')
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
                    Forms\Components\TextInput::make('download_url')
                        ->label('Download URL')
                        ->default($urls['epg'])
                        ->required()
                        ->disabled()
                        ->dehydrated(fn(): bool => true),
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
}
