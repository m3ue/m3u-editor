<?php

use App\Enums\Status;
use App\Jobs\GenerateEpgCache;
use App\Jobs\GenerateEpgCacheChunk;
use App\Jobs\ProcessEpgImportChunk;
use App\Models\Epg;
use App\Models\User;
use App\Services\EpgCacheService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// ──────────────────────────────────────────────────────────────────────────────
// Phase 1: Import chunk parallelization
// ──────────────────────────────────────────────────────────────────────────────

it('ProcessEpgImportChunk uses the Batchable trait', function () {
    $traits = class_uses_recursive(ProcessEpgImportChunk::class);
    expect($traits)->toContain(Batchable::class);
});

// ──────────────────────────────────────────────────────────────────────────────
// Phase 2: Cache generation parallelization
// ──────────────────────────────────────────────────────────────────────────────

it('GenerateEpgCacheChunk uses the Batchable trait', function () {
    $traits = class_uses_recursive(GenerateEpgCacheChunk::class);
    expect($traits)->toContain(Batchable::class);
});

it('EpgCacheService pre-scan splits programmes into chunk files', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->createQuietly([
        'uuid' => 'test-prescan-uuid',
        'url' => 'http://example.com/test.xml',
        'status' => Status::Processing,
        'is_cached' => false,
    ]);

    // Create a minimal EPG XML file
    $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <channel id="ch1">
    <display-name>Channel One</display-name>
    <icon src="http://example.com/ch1.png"/>
  </channel>
  <channel id="ch2">
    <display-name>Channel Two</display-name>
  </channel>
  <programme start="20260414060000 +0000" stop="20260414070000 +0000" channel="ch1">
    <title>Morning Show</title>
    <desc>A morning programme</desc>
  </programme>
  <programme start="20260414070000 +0000" stop="20260414080000 +0000" channel="ch1">
    <title>News Hour</title>
    <category>News</category>
  </programme>
  <programme start="20260414080000 +0000" stop="20260414090000 +0000" channel="ch2">
    <title>Talk Show</title>
  </programme>
</tv>
XML;

    Storage::disk('local')->put($epg->file_path, $xmlContent);

    $service = new EpgCacheService;
    $result = $service->extractChannelsAndSplitProgrammes(
        $epg,
        Storage::disk('local')->path($epg->file_path),
        2,
        3
    );

    expect($result)
        ->toBeArray()
        ->toHaveKeys(['chunk_count', 'chunk_paths', 'channel_count', 'programme_count', 'date_range'])
        ->and($result['channel_count'])->toBe(2)
        ->and($result['programme_count'])->toBe(3)
        ->and($result['chunk_count'])->toBeGreaterThanOrEqual(1)
        ->and($result['chunk_paths'])->toBeArray()
        ->and($result['date_range']['min_date'])->toBe('2026-04-14')
        ->and($result['date_range']['max_date'])->toBe('2026-04-14');

    // Verify channels.json was created
    $channelsPath = "epg-cache/{$epg->uuid}/v1/channels.json";
    expect(Storage::disk('local')->exists($channelsPath))->toBeTrue();

    $channels = json_decode(Storage::disk('local')->get($channelsPath), true);
    expect($channels)
        ->toHaveKey('ch1')
        ->toHaveKey('ch2');
    expect($channels['ch1']['display_name'])->toBe('Channel One');

    // Verify chunk files exist
    foreach ($result['chunk_paths'] as $chunkPath) {
        expect(Storage::disk('local')->exists($chunkPath))->toBeTrue();
    }

    // Verify chunk file content is valid JSONL with raw XML
    $firstChunkContent = Storage::disk('local')->get($result['chunk_paths'][0]);
    $lines = array_filter(explode("\n", trim($firstChunkContent)));
    expect(count($lines))->toBeGreaterThanOrEqual(1);

    $firstLine = json_decode($lines[0], true);
    expect($firstLine)
        ->toHaveKeys(['channel', 'start', 'stop', 'date', 'xml'])
        ->and($firstLine['channel'])->toBe('ch1')
        ->and($firstLine['date'])->toBe('2026-04-14')
        ->and($firstLine['xml'])->toContain('<programme');
});

it('GenerateEpgCacheChunk parses raw XML and writes programme JSONL files', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->createQuietly([
        'uuid' => 'test-chunk-uuid',
        'status' => Status::Processing,
        'cache_progress' => 10,
    ]);

    // Create a chunk file with raw programme XML
    $chunkData = [
        json_encode([
            'channel' => 'ch1',
            'start' => '2026-04-14T06:00:00.000000Z',
            'stop' => '2026-04-14T07:00:00.000000Z',
            'date' => '2026-04-14',
            'xml' => '<programme start="20260414060000 +0000" stop="20260414070000 +0000" channel="ch1"><title>Morning Show</title><desc>A morning programme</desc></programme>',
        ], JSON_UNESCAPED_UNICODE),
        json_encode([
            'channel' => 'ch2',
            'start' => '2026-04-15T08:00:00.000000Z',
            'stop' => '2026-04-15T09:00:00.000000Z',
            'date' => '2026-04-15',
            'xml' => '<programme start="20260415080000 +0000" stop="20260415090000 +0000" channel="ch2"><title>Evening News</title><category>News</category></programme>',
        ], JSON_UNESCAPED_UNICODE),
    ];

    $chunkPath = "epg-cache/{$epg->uuid}/v1/tmp/chunk-0.jsonl";
    Storage::disk('local')->put($chunkPath, implode("\n", $chunkData)."\n");

    // Create the cache directory
    Storage::disk('local')->makeDirectory("epg-cache/{$epg->uuid}/v1");

    $job = new GenerateEpgCacheChunk($epg->uuid, $chunkPath, 1);
    $job->handle();

    // Verify programme files were created for both dates
    $file14 = "epg-cache/{$epg->uuid}/v1/programmes-2026-04-14.jsonl";
    $file15 = "epg-cache/{$epg->uuid}/v1/programmes-2026-04-15.jsonl";

    expect(Storage::disk('local')->exists($file14))->toBeTrue();
    expect(Storage::disk('local')->exists($file15))->toBeTrue();

    // Verify content of date 14
    $content14 = Storage::disk('local')->get($file14);
    $lines14 = array_filter(explode("\n", trim($content14)));
    expect(count($lines14))->toBe(1);

    $parsed = json_decode($lines14[0], true);
    expect($parsed['channel'])->toBe('ch1');
    expect($parsed['programme']['title'])->toBe('Morning Show');
    expect($parsed['programme']['desc'])->toBe('A morning programme');

    // Verify content of date 15
    $content15 = Storage::disk('local')->get($file15);
    $lines15 = array_filter(explode("\n", trim($content15)));
    $parsed15 = json_decode($lines15[0], true);
    expect($parsed15['channel'])->toBe('ch2');
    expect($parsed15['programme']['title'])->toBe('Evening News');
    expect($parsed15['programme']['category'])->toBe('News');
});

it('finalizeCacheAfterChunks writes metadata and cleans up tmp', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->createQuietly([
        'uuid' => 'test-finalize-uuid',
        'status' => Status::Processing,
        'is_cached' => false,
    ]);

    // Create cache dir and temp chunk file
    $cacheDir = "epg-cache/{$epg->uuid}/v1";
    $tmpDir = "{$cacheDir}/tmp";
    Storage::disk('local')->makeDirectory($tmpDir);
    Storage::disk('local')->put("{$tmpDir}/chunk-0.jsonl", 'test data');

    $service = new EpgCacheService;
    $service->finalizeCacheAfterChunks($epg, 10, 500, [
        'min_date' => '2026-04-14',
        'max_date' => '2026-04-20',
    ]);

    // Verify metadata file was created
    $metadataPath = "{$cacheDir}/metadata.json";
    expect(Storage::disk('local')->exists($metadataPath))->toBeTrue();

    $metadata = json_decode(Storage::disk('local')->get($metadataPath), true);
    expect($metadata['total_channels'])->toBe(10);
    expect($metadata['total_programmes'])->toBe(500);
    expect($metadata['programme_date_range']['min_date'])->toBe('2026-04-14');

    // Verify EPG model was updated
    $epg->refresh();
    expect($epg->is_cached)->toBeTrue();
    expect((int) $epg->cache_progress)->toBe(100);
    expect($epg->channel_count)->toBe(10);
    expect($epg->programme_count)->toBe(500);

    // Verify tmp directory was cleaned up
    expect(Storage::disk('local')->exists("{$tmpDir}/chunk-0.jsonl"))->toBeFalse();
});

it('cacheEpgData returns array in parallel mode for batch dispatch', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->createQuietly([
        'uuid' => 'test-parallel-uuid',
        'url' => 'http://example.com/epg.xml',
        'status' => Status::Processing,
        'is_cached' => false,
    ]);

    $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <channel id="ch1"><display-name>Ch One</display-name></channel>
  <programme start="20260414060000 +0000" stop="20260414070000 +0000" channel="ch1">
    <title>Show</title>
  </programme>
</tv>
XML;

    Storage::disk('local')->put($epg->file_path, $xmlContent);

    $service = new EpgCacheService;
    $result = $service->cacheEpgData($epg, parallel: true);

    expect($result)->toBeArray();
    expect($result)->toHaveKeys(['chunk_count', 'chunk_paths', 'channel_count', 'programme_count']);
});

it('cacheEpgData returns bool in synchronous mode', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->createQuietly([
        'uuid' => 'test-sync-uuid',
        'url' => 'http://example.com/epg.xml',
        'status' => Status::Processing,
        'is_cached' => false,
    ]);

    $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <channel id="ch1"><display-name>Ch One</display-name></channel>
  <programme start="20260414060000 +0000" stop="20260414070000 +0000" channel="ch1">
    <title>Show</title>
  </programme>
</tv>
XML;

    Storage::disk('local')->put($epg->file_path, $xmlContent);

    $service = new EpgCacheService;
    $result = $service->cacheEpgData($epg, parallel: false);

    expect($result)->toBeTrue();
    $epg->refresh();
    expect($epg->is_cached)->toBeTrue();
    expect((int) $epg->cache_progress)->toBe(100);
});

it('GenerateEpgCache dispatches batch for parallel cache generation', function () {
    Bus::fake();
    Storage::fake('local');

    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->createQuietly([
        'uuid' => 'test-batch-dispatch-uuid',
        'url' => 'http://example.com/epg.xml',
        'status' => Status::Completed,
        'is_cached' => false,
    ]);

    $xmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <channel id="ch1"><display-name>Ch One</display-name></channel>
  <programme start="20260414060000 +0000" stop="20260414070000 +0000" channel="ch1">
    <title>Show</title>
  </programme>
</tv>
XML;

    Storage::disk('local')->put($epg->file_path, $xmlContent);

    // Since Bus is faked, the batch will be captured
    Bus::assertNothingDispatched();

    $job = new GenerateEpgCache($epg->uuid, notify: true);
    $job->handle(app(EpgCacheService::class));

    // The job should have dispatched a batch of GenerateEpgCacheChunk jobs
    Bus::assertBatched(function (PendingBatch $batch) {
        return $batch->jobs->every(fn ($job) => $job instanceof GenerateEpgCacheChunk);
    });
});
