<?php

namespace App\Jobs;

use App\Models\Epg;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use XMLReader;

class GenerateEpgCacheChunk implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public $tries = 1;

    public $timeout = 60 * 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $epgUuid,
        public string $chunkFilePath,
        public int $totalChunks,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $epg = Epg::where('uuid', $this->epgUuid)->first();
        if (! $epg) {
            return;
        }

        $fullChunkPath = Storage::disk('local')->path($this->chunkFilePath);
        if (! file_exists($fullChunkPath)) {
            Log::error("Cache chunk file not found: {$this->chunkFilePath}");

            return;
        }

        $fileHandles = [];

        try {
            $cacheDir = "epg-cache/{$epg->uuid}/v1";
            $processedCount = 0;

            $handle = fopen($fullChunkPath, 'r');
            if (! $handle) {
                Log::error("Failed to open chunk file: {$this->chunkFilePath}");

                return;
            }

            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $raw = json_decode($line, true);
                if (! $raw || ! isset($raw['xml'], $raw['channel'], $raw['date'])) {
                    continue;
                }

                $programme = $this->parseProgrammeXml($raw['xml'], $raw['channel'], $raw['start'], $raw['stop']);
                if (! $programme || ! $programme['title']) {
                    continue;
                }

                $this->appendProgramme($cacheDir, $raw['date'], $raw['channel'], $programme, $fileHandles);
                $processedCount++;
            }

            fclose($handle);

            // Update progress atomically
            if ($this->totalChunks > 0) {
                $increment = max(1, (int) floor(99 / $this->totalChunks));
                Epg::where('uuid', $this->epgUuid)
                    ->where('cache_progress', '<', 99)
                    ->increment('cache_progress', $increment);
            }
        } finally {
            foreach ($fileHandles as $fh) {
                if (is_resource($fh)) {
                    fclose($fh);
                }
            }
        }
    }

    /**
     * Parse raw programme XML into structured array.
     *
     * @return array<string, mixed>|null
     */
    private function parseProgrammeXml(string $outerXml, string $channelId, string $startIso, ?string $stopIso): ?array
    {
        $programme = [
            'channel' => $channelId,
            'start' => $startIso,
            'stop' => $stopIso,
            'title' => '',
            'subtitle' => '',
            'desc' => '',
            'category' => '',
            'episode_num' => '',
            'rating' => '',
            'icon' => '',
            'images' => [],
            'new' => false,
        ];

        $reader = new XMLReader;
        $reader->xml($outerXml);

        while (@$reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT) {
                continue;
            }

            switch ($reader->name) {
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
                case 'episode-num':
                    $programme['episode_num'] = trim($reader->readString() ?: '');
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
        $reader->close();

        return $programme;
    }

    /**
     * Append a parsed programme to the appropriate date-based JSONL file.
     *
     * @param  array<string, resource>  $fileHandles
     */
    private function appendProgramme(string $cacheDir, string $date, string $channelId, array $programme, array &$fileHandles): void
    {
        if (! isset($fileHandles[$date])) {
            $programmesPath = "{$cacheDir}/programmes-{$date}.jsonl";
            $fullPath = Storage::disk('local')->path($programmesPath);

            $dir = dirname($fullPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $fileHandles[$date] = fopen($fullPath, 'a');
            if (! $fileHandles[$date]) {
                Log::error("Failed to open programme file for date {$date}");

                return;
            }
        }

        $record = [
            'channel' => $channelId,
            'programme' => $programme,
        ];

        $line = json_encode($record, JSON_UNESCAPED_UNICODE)."\n";

        // Use LOCK_EX via flock for concurrent write safety
        flock($fileHandles[$date], LOCK_EX);
        fwrite($fileHandles[$date], $line);
        flock($fileHandles[$date], LOCK_UN);

        // Close handles if we have too many open
        if (count($fileHandles) > 50) {
            foreach ($fileHandles as $d => $handle) {
                if ($d !== $date && is_resource($handle)) {
                    fclose($handle);
                    unset($fileHandles[$d]);
                }
            }
        }
    }
}
