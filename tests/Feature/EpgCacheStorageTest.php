<?php

use App\Models\Epg;
use App\Models\User;
use App\Services\EpgCacheService;
use App\Services\EpgCacheStorage;
use App\Services\EpgProgrammeStore;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Bus::fake();
    Storage::fake('local');
});

/** The one canonical cache directory an EPG's programmes live in. */
function canonicalEpgCacheDirectory(Epg $epg): string
{
    return "epg-cache/{$epg->uuid}/v2";
}

/**
 * Write the complete canonical cache exactly as a finished rebuild leaves it:
 * metadata, channels and the single programmes.sqlite store.
 */
function writeCanonicalEpgCache(Epg $epg, string $title): string
{
    $directory = canonicalEpgCacheDirectory($epg);
    $start = now()->startOfDay()->addHour();

    Storage::disk('local')->put("{$directory}/metadata.json", json_encode([
        'cache_created' => time(),
        'cache_version' => 'v2',
        'epg_uuid' => $epg->uuid,
    ], JSON_THROW_ON_ERROR));

    Storage::disk('local')->put("{$directory}/channels.json", json_encode([
        'channel.one' => ['id' => 'channel.one', 'display_name' => $title],
    ], JSON_THROW_ON_ERROR));

    $store = new EpgProgrammeStore;
    $store->beginWrite(Storage::disk('local')->path("{$directory}/programmes.sqlite"));
    $store->insert('channel.one', $start->format('Y-m-d'), $start->getTimestamp(), null, [
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => $start->toISOString(),
        'title' => $title,
    ]);
    $store->finish();

    return $directory;
}

/** @return list<string> */
function epgCacheFiles(Epg $epg): array
{
    $files = Storage::disk('local')->allFiles("epg-cache/{$epg->uuid}");
    sort($files);

    return $files;
}

/** @return list<string> */
function canonicalEpgCacheFiles(Epg $epg): array
{
    $root = "epg-cache/{$epg->uuid}";

    return ["{$root}/v2/channels.json", "{$root}/v2/metadata.json", "{$root}/v2/programmes.sqlite"];
}

function storedProgrammeTitle(Epg $epg, int $rowid = 1): ?string
{
    $store = EpgProgrammeStore::openRead(Storage::disk('local')->path(canonicalEpgCacheDirectory($epg).'/programmes.sqlite'));
    try {
        $rows = $store->readRowsByIds([$rowid]);
    } finally {
        $store->close();
    }

    $row = $rows[$rowid] ?? null;
    if ($row === null) {
        return null;
    }

    return EpgProgrammeStore::hydrate(json_decode($row['data'], true) ?: [], $row['channel_id'], $row['start_ts'], $row['stop_ts'])['title'];
}

it('resolves the one canonical cache directory and falls back to legacy versions for reads', function () {
    $epg = Epg::factory()->for(User::factory())->create();
    $storage = app(EpgCacheStorage::class);

    expect($storage->resolve($epg))->toBe(canonicalEpgCacheDirectory($epg));

    Storage::disk('local')->put("epg-cache/{$epg->uuid}/v1/metadata.json", '{"cache_version":"v1"}');

    expect($storage->resolve($epg))->toBe("epg-cache/{$epg->uuid}/v1")
        ->and($storage->isCanonical($epg, "epg-cache/{$epg->uuid}/v1"))->toBeFalse();

    writeCanonicalEpgCache($epg, 'Canonical programme');

    expect($storage->resolve($epg))->toBe(canonicalEpgCacheDirectory($epg))
        ->and($storage->isCanonical($epg, canonicalEpgCacheDirectory($epg)))->toBeTrue();
});

it('publishes a completed rebuild as a single cache file set without copies or pointers', function () {
    $epg = Epg::factory()->for(User::factory())->create(['url' => 'https://example.com/guide.xml']);
    $start = now()->startOfDay()->addHour();
    $xml = "<?xml version=\"1.0\"?><tv>\n".
        "<channel id=\"channel.one\"><display-name>Channel One</display-name></channel>\n".
        "<programme start=\"{$start->format('YmdHis O')}\" channel=\"channel.one\"><title>Published programme</title></programme>\n".
        '</tv>';
    Storage::disk('local')->put($epg->file_path, gzencode($xml));

    expect(app(EpgCacheService::class)->cacheEpgData($epg))->toBeTrue();

    expect(epgCacheFiles($epg))->toBe(canonicalEpgCacheFiles($epg))
        ->and(Storage::disk('local')->exists(canonicalEpgCacheDirectory($epg).'/active-generation.json'))->toBeFalse()
        ->and(Storage::disk('local')->directories("epg-cache/{$epg->uuid}"))->toBe([canonicalEpgCacheDirectory($epg)]);
});

it('does not accumulate cache files or bytes across consecutive rebuilds', function () {
    $epg = Epg::factory()->for(User::factory())->create(['url' => 'https://example.com/guide.xml']);
    $start = now()->startOfDay()->addHour();
    $xml = "<?xml version=\"1.0\"?><tv>\n".
        "<channel id=\"channel.one\"><display-name>Channel One</display-name></channel>\n".
        "<programme start=\"{$start->format('YmdHis O')}\" channel=\"channel.one\"><title>Stable programme</title></programme>\n".
        '</tv>';
    Storage::disk('local')->put($epg->file_path, gzencode($xml));

    $service = app(EpgCacheService::class);
    expect($service->cacheEpgData($epg))->toBeTrue();

    $firstRun = array_map(fn (string $file): int => Storage::disk('local')->size($file), epgCacheFiles($epg));

    expect($service->cacheEpgData($epg))->toBeTrue();

    expect(epgCacheFiles($epg))->toBe(canonicalEpgCacheFiles($epg))
        ->and(array_map(fn (string $file): int => Storage::disk('local')->size($file), epgCacheFiles($epg)))->toBe($firstRun);
});

it('refuses to publish an incomplete rebuild and leaves the canonical cache untouched', function () {
    $epg = Epg::factory()->for(User::factory())->create();
    $directory = writeCanonicalEpgCache($epg, 'Original');
    $storage = app(EpgCacheStorage::class);

    $directoryStaged = $storage->stagedPath($directory, EpgCacheStorage::METADATA_FILE);
    Storage::disk('local')->put($directoryStaged, json_encode(['cache_created' => time(), 'cache_version' => 'v2']));

    $staged = [
        EpgCacheStorage::PROGRAMMES_DB_FILE => $storage->stagedPath($directory, EpgCacheStorage::PROGRAMMES_DB_FILE),
        EpgCacheStorage::CHANNELS_FILE => $storage->stagedPath($directory, EpgCacheStorage::CHANNELS_FILE),
        EpgCacheStorage::METADATA_FILE => $directoryStaged,
    ];

    expect(fn () => $storage->publishRebuild($epg, $staged))->toThrow(RuntimeException::class);

    expect(storedProgrammeTitle($epg))->toBe('Original')
        ->and(json_decode(Storage::disk('local')->get("{$directory}/channels.json"), true))->toHaveKey('channel.one');

    $storage->discardStaged($staged);

    expect(epgCacheFiles($epg))->toBe(canonicalEpgCacheFiles($epg));
});

it('refuses to publish a rebuild whose staged file is not a JSON object', function (string $brokenFile, string $contents): void {
    $epg = Epg::factory()->for(User::factory())->create();
    $directory = writeCanonicalEpgCache($epg, 'Original');
    $storage = app(EpgCacheStorage::class);

    $staged = [];
    foreach ([EpgCacheStorage::PROGRAMMES_DB_FILE, EpgCacheStorage::CHANNELS_FILE, EpgCacheStorage::METADATA_FILE] as $file) {
        $staged[$file] = $storage->stagedPath($directory, $file);
    }

    $store = new EpgProgrammeStore;
    $store->beginWrite(Storage::disk('local')->path($staged[EpgCacheStorage::PROGRAMMES_DB_FILE]));
    $store->insert('channel.one', now()->format('Y-m-d'), now()->getTimestamp(), null, [
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => now()->toISOString(),
        'title' => 'Replacement',
    ]);
    $store->finish();

    Storage::disk('local')->put($staged[EpgCacheStorage::CHANNELS_FILE], '{"channel.one":{"display_name":"Replacement"}}');
    Storage::disk('local')->put($staged[EpgCacheStorage::METADATA_FILE], json_encode(['cache_created' => time(), 'cache_version' => 'v2'], JSON_THROW_ON_ERROR));
    Storage::disk('local')->put($staged[$brokenFile], $contents);

    expect(fn () => $storage->publishRebuild($epg, $staged))->toThrow(RuntimeException::class);

    expect(storedProgrammeTitle($epg))->toBe('Original');

    $storage->discardStaged($staged);

    expect(epgCacheFiles($epg))->toBe(canonicalEpgCacheFiles($epg));
})->with([
    'a truncated metadata file' => [EpgCacheStorage::METADATA_FILE, '{"cache_created": 1'],
    'a channels file that is no object' => [EpgCacheStorage::CHANNELS_FILE, 'null'],
]);

it('publishes a rebuild under the per-EPG mutation lock so readers never see a half-swapped cache', function () {
    $epg = Epg::factory()->for(User::factory())->create();
    $directory = writeCanonicalEpgCache($epg, 'Original');
    $storage = app(EpgCacheStorage::class);

    $staged = [];
    foreach ([EpgCacheStorage::PROGRAMMES_DB_FILE, EpgCacheStorage::CHANNELS_FILE, EpgCacheStorage::METADATA_FILE] as $file) {
        $staged[$file] = $storage->stagedPath($directory, $file);
    }

    $store = new EpgProgrammeStore;
    $store->beginWrite(Storage::disk('local')->path($staged[EpgCacheStorage::PROGRAMMES_DB_FILE]));
    $store->insert('channel.one', now()->format('Y-m-d'), now()->getTimestamp(), null, [
        ...EpgProgrammeStore::EMPTY_PROGRAMME,
        'channel' => 'channel.one',
        'start' => now()->toISOString(),
        'title' => 'Replacement',
    ]);
    $store->finish();
    Storage::disk('local')->put($staged[EpgCacheStorage::CHANNELS_FILE], '{"channel.one":{"display_name":"Replacement"}}');
    Storage::disk('local')->put($staged[EpgCacheStorage::METADATA_FILE], json_encode(['cache_created' => time(), 'cache_version' => 'v2']));

    $lock = Mockery::mock();
    $observations = [];
    Cache::shouldReceive('lock')
        ->once()
        ->with('epg-cache-mutation:'.$epg->uuid, 30)
        ->andReturn($lock);
    $lock->shouldReceive('block')
        ->once()
        ->with(10, Mockery::type(Closure::class))
        ->andReturnUsing(function (int $seconds, Closure $callback) use ($epg, &$observations): void {
            $observations[] = storedProgrammeTitle($epg);

            $callback();
        });

    $storage->publishRebuild($epg, $staged);

    expect($observations)->toBe(['Original'])
        ->and(storedProgrammeTitle($epg))->toBe('Replacement')
        ->and(epgCacheFiles($epg))->toBe(canonicalEpgCacheFiles($epg));
});

it('prunes staging files abandoned by a crashed rebuild but keeps fresh ones', function () {
    $epg = Epg::factory()->for(User::factory())->create();
    $storage = app(EpgCacheStorage::class);
    $directory = $storage->beginRebuild($epg);

    $abandoned = $directory.'/programmes.sqlite.building-4242-deadbeef';
    $fresh = $directory.'/channels.json.building-4243-cafebabe';
    Storage::disk('local')->put($abandoned, 'abandoned');
    Storage::disk('local')->put($fresh, 'fresh');
    touch(Storage::disk('local')->path($abandoned), time() - 43200);

    $storage->beginRebuild($epg);

    expect(Storage::disk('local')->exists($abandoned))->toBeFalse()
        ->and(Storage::disk('local')->exists($fresh))->toBeTrue();
});
