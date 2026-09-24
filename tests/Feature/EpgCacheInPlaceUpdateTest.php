<?php

use App\Models\Epg;
use App\Models\Plugin;
use App\Models\PluginRun;
use App\Models\User;
use App\Plugins\Support\PluginExecutionContext;
use App\Services\EpgCacheBusyException;
use App\Services\EpgCacheEnrichmentService;
use App\Services\EpgCacheStorage;
use App\Services\EpgProgrammeStore;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Bus::fake();
    Storage::fake('local');
});

function inPlaceProgrammesPath(Epg $epg): string
{
    return Storage::disk('local')->path("epg-cache/{$epg->uuid}/v2/programmes.sqlite");
}

function inPlaceContext(User $user): PluginExecutionContext
{
    $plugin = Plugin::query()->create([
        'plugin_id' => 'epg-enrichment-inplace-'.fake()->uuid(),
        'name' => 'EPG Enrichment In-Place Fixture',
        'version' => '1.0.0',
        'api_version' => '1.0.0',
        'description' => 'Fixture',
        'capabilities' => ['epg_cache_enrichment'],
        'hooks' => [],
        'permissions' => [],
        'schema_definition' => ['tables' => []],
        'actions' => [],
        'settings_schema' => [],
        'settings' => [],
        'data_ownership' => ['tables' => [], 'directories' => [], 'files' => []],
        'source_type' => 'local_directory',
        'path' => storage_path('app/testing-plugin-sources/'.fake()->uuid()),
        'available' => true,
        'enabled' => true,
        'installation_status' => 'installed',
        'trust_state' => 'trusted',
        'validation_status' => 'valid',
        'integrity_status' => 'verified',
    ]);
    $run = PluginRun::query()->create([
        'extension_plugin_id' => $plugin->id,
        'user_id' => $user->id,
        'status' => 'running',
        'trigger' => 'manual',
        'invocation_type' => 'action',
        'dry_run' => false,
        'payload' => [],
    ]);

    return new PluginExecutionContext($plugin, $run, 'manual', false, null, $user, []);
}

/**
 * Write the one canonical cache file set: metadata, channels and the single
 * programmes.sqlite store holding the given programmes.
 *
 * @param  list<string>  $titles
 */
function writeInPlaceCache(Epg $epg, array $titles): void
{
    $directory = "epg-cache/{$epg->uuid}/v2";
    Storage::disk('local')->put("{$directory}/metadata.json", json_encode([
        'cache_created' => time(),
        'cache_version' => 'v2',
    ], JSON_THROW_ON_ERROR));
    Storage::disk('local')->put("{$directory}/channels.json", '{}');

    $store = new EpgProgrammeStore;
    $store->beginWrite(Storage::disk('local')->path("{$directory}/programmes.sqlite"));
    foreach ($titles as $index => $title) {
        $start = Carbon::parse('2026-09-16 00:00:00 UTC')->addMinutes($index);
        $store->insert('channel.one', $start->format('Y-m-d'), $start->getTimestamp(), null, [
            ...EpgProgrammeStore::EMPTY_PROGRAMME,
            'channel' => 'channel.one',
            'start' => $start->toISOString(),
            'title' => $title,
        ]);
    }
    $store->finish();
}

function inPlaceTitles(Epg $epg): array
{
    $store = EpgProgrammeStore::openRead(inPlaceProgrammesPath($epg));
    try {
        $rows = $store->readRowsByIds([1, 2, 3]);
    } finally {
        $store->close();
    }

    $titles = [];
    foreach ($rows as $rowid => $row) {
        $titles[$rowid] = EpgProgrammeStore::hydrate(json_decode($row['data'], true) ?: [], $row['channel_id'], $row['start_ts'], $row['stop_ts'])['title'];
    }

    return $titles;
}

function inPlaceRevision(Epg $epg): ?string
{
    $store = EpgProgrammeStore::openRead(inPlaceProgrammesPath($epg));
    try {
        return $store->readCacheRevision();
    } finally {
        $store->close();
    }
}

/** Simulate a probe write that changed one row without publishing a new revision. */
function overwriteInPlaceTitle(Epg $epg, int $rowid, string $title): void
{
    $pdo = new PDO('sqlite:'.inPlaceProgrammesPath($epg));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->prepare('UPDATE programmes SET data = ? WHERE rowid = ?')->execute([json_encode(['title' => $title], JSON_THROW_ON_ERROR), $rowid]);
    $pdo = null;
}

it('publishes a conditional update atomically so a concurrent reader sees the pre-commit state', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    writeInPlaceCache($epg, ['Original']);

    $writer = new class extends EpgProgrammeStore
    {
        /** @var list<string> */
        public array $peerTitles = [];

        public string $peerPath = '';

        protected function beforeCommit(): void
        {
            $peer = EpgProgrammeStore::openRead($this->peerPath);
            try {
                $rows = $peer->readRowsByIds([1]);
            } finally {
                $peer->close();
            }

            $row = $rows[1];
            $this->peerTitles[] = EpgProgrammeStore::hydrate(json_decode($row['data'], true) ?: [], $row['channel_id'], $row['start_ts'], $row['stop_ts'])['title'];
        }
    };
    $writer->peerPath = inPlaceProgrammesPath($epg);

    $service = new class($writer, app(EpgCacheStorage::class)) extends EpgCacheEnrichmentService
    {
        public function __construct(private EpgProgrammeStore $writer, EpgCacheStorage $storage)
        {
            parent::__construct($storage);
        }

        protected function openWriter(string $sqlitePath): EpgProgrammeStore
        {
            $this->writer->openWrite($sqlitePath);

            return $this->writer;
        }
    };

    $context = inPlaceContext($user);
    $snapshot = $service->snapshot($context, $epg, []);

    $result = $service->apply($context, $epg, $snapshot['token'], [[
        'locator' => $snapshot['programmes'][0]['locator'],
        'row_revision' => $snapshot['programmes'][0]['row_revision'],
        'changes' => ['title' => 'Updated'],
    ]]);

    expect($result['status'])->toBe('applied')
        ->and($writer->peerTitles)->toBe(['Original'])
        ->and(inPlaceTitles($epg))->toBe([1 => 'Updated']);
});

it('rejects a batch whose row changed under the mutation lock and writes nothing', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    writeInPlaceCache($epg, ['First original', 'Second original']);

    $service = app(EpgCacheEnrichmentService::class);
    $context = inPlaceContext($user);
    $snapshot = $service->snapshot($context, $epg, ['limit' => 2]);
    $revision = inPlaceRevision($epg);

    $lock = Mockery::mock();
    Cache::shouldReceive('lock')->once()->with('epg-cache-mutation:'.$epg->uuid, 30)->andReturn($lock);
    $lock->shouldReceive('block')->once()->with(10, Mockery::type(Closure::class))->andReturnUsing(function (int $seconds, Closure $callback) use ($epg): array {
        overwriteInPlaceTitle($epg, 2, 'Changed by someone else');

        return $callback();
    });

    $result = $service->apply($context, $epg, $snapshot['token'], [
        [
            'locator' => $snapshot['programmes'][0]['locator'],
            'row_revision' => $snapshot['programmes'][0]['row_revision'],
            'changes' => ['title' => 'Patched first'],
        ],
        [
            'locator' => $snapshot['programmes'][1]['locator'],
            'row_revision' => $snapshot['programmes'][1]['row_revision'],
            'changes' => ['title' => 'Patched second'],
        ],
    ]);

    expect($result['status'])->toBe('conflict')
        ->and(inPlaceTitles($epg))->toBe([1 => 'First original', 2 => 'Changed by someone else'])
        ->and(inPlaceRevision($epg))->toBe($revision);
});

it('bumps the cache revision in the same commit so earlier snapshots go stale', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    writeInPlaceCache($epg, ['Original']);

    $service = app(EpgCacheEnrichmentService::class);
    $context = inPlaceContext($user);
    $first = $service->snapshot($context, $epg, []);

    expect($service->apply($context, $epg, $first['token'], [[
        'locator' => $first['programmes'][0]['locator'],
        'row_revision' => $first['programmes'][0]['row_revision'],
        'changes' => ['title' => 'Enriched'],
    ]])['status'])->toBe('applied');

    expect(inPlaceRevision($epg))->not->toBe($first['cache_revision'])
        ->and($service->apply($context, $epg, $first['token'], [])['status'])->toBe('stale_snapshot');

    $second = $service->snapshot($context, $epg, []);

    expect($second['programmes'][0]['programme']['title'])->toBe('Enriched')
        ->and($service->apply($context, $epg, $second['token'], [[
            'locator' => $second['programmes'][0]['locator'],
            'row_revision' => $second['programmes'][0]['row_revision'],
            'changes' => ['title' => 'Enriched twice'],
        ]])['status'])->toBe('applied')
        ->and(inPlaceTitles($epg))->toBe([1 => 'Enriched twice']);
});

it('reports a transient error instead of an invalid patch when the cache file is locked by another writer', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    writeInPlaceCache($epg, ['Original']);

    $service = new class(app(EpgCacheStorage::class)) extends EpgCacheEnrichmentService
    {
        protected function openWriter(string $sqlitePath): EpgProgrammeStore
        {
            $store = new EpgProgrammeStore;
            $store->openWrite($sqlitePath, 50);

            return $store;
        }
    };
    $context = inPlaceContext($user);
    $snapshot = $service->snapshot($context, $epg, []);
    $patch = [[
        'locator' => $snapshot['programmes'][0]['locator'],
        'row_revision' => $snapshot['programmes'][0]['row_revision'],
        'changes' => ['title' => 'Updated'],
    ]];

    $blocker = new PDO('sqlite:'.inPlaceProgrammesPath($epg));
    $blocker->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $blocker->exec('BEGIN IMMEDIATE');
    $blocker->exec("UPDATE programmes SET data = '{}' WHERE rowid = 1 AND 0");

    try {
        expect($service->apply($context, $epg, $snapshot['token'], $patch)['status'])->toBe('transient_error');
    } finally {
        $blocker->exec('ROLLBACK');
        $blocker = null;
    }

    expect($service->apply($context, $epg, $snapshot['token'], $patch)['status'])->toBe('applied')
        ->and(inPlaceTitles($epg))->toBe([1 => 'Updated']);
});

it('reports a transient error instead of a legacy read-only cache when the store is locked during the revision pre-check', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    writeInPlaceCache($epg, ['Original']);

    // Short SQLite busy timeout: a contended read must fail fast instead of
    // waiting it out (same reason the write path is opened with a short one).
    $service = new class(app(EpgCacheStorage::class)) extends EpgCacheEnrichmentService
    {
        protected function openReader(Epg $epg): ?EpgProgrammeStore
        {
            return EpgProgrammeStore::openRead(inPlaceProgrammesPath($epg), 50);
        }
    };
    $context = inPlaceContext($user);
    $snapshot = $service->snapshot($context, $epg, []);

    // The revision pre-check runs outside the mutation lock, so another writer
    // can hold SQLite's exclusive lock while the plugin's batch is validated.
    $blocker = new PDO('sqlite:'.inPlaceProgrammesPath($epg));
    $blocker->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $blocker->exec('BEGIN EXCLUSIVE');

    try {
        // An empty batch keeps the pre-check on the revision read alone, so the
        // lock can only be reported as contention - never as a legacy cache.
        expect($service->apply($context, $epg, $snapshot['token'], [])['status'])->toBe('transient_error');
    } finally {
        $blocker->exec('ROLLBACK');
        $blocker = null;
    }

    expect($service->apply($context, $epg, $snapshot['token'], [[
        'locator' => $snapshot['programmes'][0]['locator'],
        'row_revision' => $snapshot['programmes'][0]['row_revision'],
        'changes' => ['title' => 'Updated'],
    ]])['status'])->toBe('applied')
        ->and(inPlaceTitles($epg))->toBe([1 => 'Updated']);
});

it('reports a locked store as contention when reading rows by rowid', function (): void {
    $epg = Epg::factory()->for(User::factory())->create();
    writeInPlaceCache($epg, ['Original']);

    // A short busy timeout, so the contended read fails fast instead of waiting
    // the driver default out.
    $store = EpgProgrammeStore::openRead(inPlaceProgrammesPath($epg), 50);

    $blocker = new PDO('sqlite:'.inPlaceProgrammesPath($epg));
    $blocker->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $blocker->exec('BEGIN EXCLUSIVE');

    try {
        expect(fn () => $store->readRowsByIds([1]))->toThrow(EpgCacheBusyException::class);
    } finally {
        $store->close();
        $blocker->exec('ROLLBACK');
        $blocker = null;
    }
});

it('reports a locked store as contention when reading a page by rowid', function (): void {
    $epg = Epg::factory()->for(User::factory())->create();
    writeInPlaceCache($epg, ['Original']);

    // A short busy timeout, so the contended read fails fast instead of waiting
    // the driver default out.
    $store = EpgProgrammeStore::openRead(inPlaceProgrammesPath($epg), 50);

    $blocker = new PDO('sqlite:'.inPlaceProgrammesPath($epg));
    $blocker->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $blocker->exec('BEGIN EXCLUSIVE');

    try {
        // A paged read that lost the race for the lock is contention, not a
        // verdict on the store: it must be reported as retryable like the
        // revision and by-rowid reads are.
        expect(fn () => $store->readPage(0, 10))->toThrow(EpgCacheBusyException::class);
    } finally {
        $store->close();
        $blocker->exec('ROLLBACK');
        $blocker = null;
    }
});

it('reports a transient error rather than an invalid patch when the store is locked during the pre-check of a non-empty batch', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    writeInPlaceCache($epg, ['Original']);

    $service = new class(app(EpgCacheStorage::class)) extends EpgCacheEnrichmentService
    {
        protected function openReader(Epg $epg): ?EpgProgrammeStore
        {
            return EpgProgrammeStore::openRead(inPlaceProgrammesPath($epg), 50);
        }
    };
    $context = inPlaceContext($user);
    $snapshot = $service->snapshot($context, $epg, []);

    $blocker = new PDO('sqlite:'.inPlaceProgrammesPath($epg));
    $blocker->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $blocker->exec('BEGIN EXCLUSIVE');

    try {
        $status = $service->apply($context, $epg, $snapshot['token'], [[
            'locator' => $snapshot['programmes'][0]['locator'],
            'row_revision' => $snapshot['programmes'][0]['row_revision'],
            'changes' => ['title' => 'Updated'],
        ]])['status'];

        // A read that lost the race for the lock is retryable contention, not a
        // verdict on the plugin's batch and not a cache-format verdict either.
        expect($status)->toBe('transient_error')
            ->and($status)->not->toBeIn(['invalid_patch', 'legacy_cache_read_only']);
    } finally {
        $blocker->exec('ROLLBACK');
        $blocker = null;
    }
});

it('reports a transient error instead of throwing when the canonical store is locked during a snapshot', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    writeInPlaceCache($epg, ['Original']);

    $service = new class(app(EpgCacheStorage::class)) extends EpgCacheEnrichmentService
    {
        protected function openReader(Epg $epg): ?EpgProgrammeStore
        {
            return EpgProgrammeStore::openRead(inPlaceProgrammesPath($epg), 50);
        }
    };
    $context = inPlaceContext($user);

    $blocker = new PDO('sqlite:'.inPlaceProgrammesPath($epg));
    $blocker->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $blocker->exec('BEGIN EXCLUSIVE');

    try {
        // Contention is reported like apply() reports it: as a retryable status,
        // never as an uncaught exception the plugin would read as a host failure.
        expect($service->snapshot($context, $epg, [])['status'])->toBe('transient_error');
    } finally {
        $blocker->exec('ROLLBACK');
        $blocker = null;
    }

    expect($service->snapshot($context, $epg, [])['status'])->toBe('ok');
});

it('surfaces a failed update instead of reporting the patch as applied', function (): void {
    $user = User::factory()->create();
    $epg = Epg::factory()->for($user)->create();
    writeInPlaceCache($epg, ['Original']);

    $pdo = new PDO('sqlite:'.inPlaceProgrammesPath($epg));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TRIGGER reject_updates BEFORE UPDATE ON programmes BEGIN SELECT RAISE(ABORT, 'write refused'); END");
    $pdo = null;

    $service = app(EpgCacheEnrichmentService::class);
    $context = inPlaceContext($user);
    $snapshot = $service->snapshot($context, $epg, []);
    $revision = inPlaceRevision($epg);

    expect(fn () => $service->apply($context, $epg, $snapshot['token'], [[
        'locator' => $snapshot['programmes'][0]['locator'],
        'row_revision' => $snapshot['programmes'][0]['row_revision'],
        'changes' => ['title' => 'Updated'],
    ]]))->toThrow(PDOException::class);

    expect(inPlaceTitles($epg))->toBe([1 => 'Original'])
        ->and(inPlaceRevision($epg))->toBe($revision);
});
