<?php

use App\Exceptions\EpgProgrammeStoreBusyException;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\Plugin;
use App\Models\PluginRun;
use App\Models\User;
use App\Plugins\Support\PluginExecutionContext;
use App\Services\EpgCacheEnrichmentService;
use App\Services\EpgCacheService;
use App\Services\EpgProgrammeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Fixture programmes start on the next hour and run consecutively, while cachedTitles reads a single
    // date, so pin the clock to midday to keep them from crossing midnight (fails between 22:00 and 23:59).
    $this->travelTo(now()->setTime(12, 0));
    Storage::fake('local');
    Bus::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->epg = Epg::factory()->for($this->user)->create(['url' => 'https://example.com/enrich.xml']);

    $this->writeXml = function (array $titles): void {
        $start = now()->startOfHour()->addHour();
        $programmes = '';
        foreach ($titles as $index => $title) {
            $from = $start->copy()->addHours($index)->format('YmdHis O');
            $to = $start->copy()->addHours($index + 1)->format('YmdHis O');
            $programmes .= "  <programme start=\"{$from}\" stop=\"{$to}\" channel=\"enrich.a\"><title>{$title}</title><desc>Original desc</desc></programme>\n";
        }

        Storage::disk('local')->put($this->epg->file_path, gzencode(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<tv>\n"
            ."  <channel id=\"enrich.a\"><display-name>enrich.a</display-name></channel>\n"
            .$programmes.'</tv>'
        ));
    };

    $this->rebuild = function (array $titles): void {
        ($this->writeXml)($titles);
        expect(app(EpgCacheService::class)->cacheEpgData($this->epg->fresh()))->toBeTrue();
    };

    $this->context = function (array $overrides = [], ?User $user = null): PluginExecutionContext {
        $user ??= $this->user;
        $plugin = Plugin::query()->create(array_merge([
            'plugin_id' => 'epg-enrichment-'.fake()->uuid(),
            'name' => 'EPG Enrichment Fixture',
            'version' => '1.0.0',
            'api_version' => '1.0.0',
            'description' => 'Fixture',
            'capabilities' => [EpgCacheEnrichmentService::CAPABILITY],
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
        ], $overrides));
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
    };

    $this->cacheFiles = function (): array {
        $files = Storage::disk('local')->allFiles("epg-cache/{$this->epg->uuid}");
        sort($files);

        return $files;
    };

    $this->cachedTitles = function (): array {
        $date = now()->startOfHour()->addHour()->format('Y-m-d');
        $programmes = (new EpgCacheService)->getCachedProgrammes($this->epg->fresh(), $date, ['enrich.a']);

        return array_column($programmes['enrich.a'] ?? [], 'title');
    };

    $this->lockProcess = function (string $mode, string $path, int $holdMilliseconds = 1000): Process {
        $script = <<<'PHP'
require $argv[1];

$path = $argv[2];
$mode = $argv[3];
$holdMicroseconds = ((int) $argv[4]) * 1000;

if ($mode === 'exclusive') {
    App\Services\EpgProgrammeStore::withExclusivePathLock($path, function () use ($holdMicroseconds): void {
        fwrite(STDOUT, "locked\n");
        fflush(STDOUT);
        usleep($holdMicroseconds);
    });
} else {
    $store = App\Services\EpgProgrammeStore::openExisting($path);
    try {
        fwrite(STDOUT, "locked\n");
        fflush(STDOUT);
        usleep($holdMicroseconds);
    } finally {
        $store->close();
    }
}
PHP;

        return new Process([
            PHP_BINARY,
            '-r',
            $script,
            base_path('vendor/autoload.php'),
            $path,
            $mode,
            (string) $holdMilliseconds,
        ]);
    };

    $this->service = fn (): EpgCacheEnrichmentService => app(EpgCacheEnrichmentService::class);
});

it('pages through the cached programmes with an id and hash per row', function () {
    ($this->rebuild)(['One', 'Two', 'Three']);
    $context = ($this->context)();

    $first = ($this->service)()->snapshot($context, $this->epg, limit: 2);
    expect($first['status'])->toBe('ok')
        ->and(array_column(array_column($first['programmes'], 'programme'), 'title'))->toBe(['One', 'Two'])
        ->and($first['programmes'][0])->toHaveKeys(['id', 'hash'])
        ->and($first['next'])->toBe($first['programmes'][1]['id']);

    $second = ($this->service)()->snapshot($context, $this->epg, afterId: $first['next'], limit: 2);
    expect(array_column(array_column($second['programmes'], 'programme'), 'title'))->toBe(['Three'])
        ->and($second['next'])->toBeNull();
});

it('applies a patch in place, leaving untouched fields and the cache file set as-is', function () {
    ($this->rebuild)(['One', 'Two']);
    $filesBefore = ($this->cacheFiles)();
    $context = ($this->context)();
    $row = ($this->service)()->snapshot($context, $this->epg)['programmes'][0];

    $result = ($this->service)()->apply($context, $this->epg, [
        ['id' => $row['id'], 'hash' => $row['hash'], 'changes' => ['title' => 'Enriched', 'new' => true]],
    ]);

    expect($result['status'])->toBe('applied')
        ->and(($this->cachedTitles)())->toBe(['Enriched', 'Two'])
        ->and(($this->cacheFiles)())->toBe($filesBefore)
        ->and($filesBefore)->toBe([
            "epg-cache/{$this->epg->uuid}/v2/channels.json",
            "epg-cache/{$this->epg->uuid}/v2/metadata.json",
            "epg-cache/{$this->epg->uuid}/v2/programmes.sqlite",
        ]);

    $programme = (new EpgCacheService)->getCachedProgrammes($this->epg->fresh(), now()->startOfHour()->addHour()->format('Y-m-d'), ['enrich.a'])['enrich.a'][0];
    expect($programme['desc'])->toBe('Original desc')
        ->and($programme['new'])->toBeTrue();
});

it('clears served playlist XMLTV files after an applied patch', function () {
    ($this->rebuild)(['One']);
    $playlist = Playlist::factory()->for($this->user)->create(['dummy_epg' => false]);
    $epgChannel = EpgChannel::factory()->for($this->user)->for($this->epg)->create(['channel_id' => 'enrich.a']);
    Channel::factory()->for($this->user)->for($playlist)->create(['epg_channel_id' => $epgChannel->id]);
    $xmlPath = EpgCacheService::getPlaylistEpgCachePath($playlist, false);
    Storage::disk('local')->put($xmlPath, '<tv/>');

    $context = ($this->context)();
    $row = ($this->service)()->snapshot($context, $this->epg)['programmes'][0];
    ($this->service)()->apply($context, $this->epg, [['id' => $row['id'], 'hash' => $row['hash'], 'changes' => ['title' => 'Enriched']]]);

    Storage::disk('local')->assertMissing($xmlPath);
});

it('reports noop when the patch changes nothing', function () {
    ($this->rebuild)(['One']);
    $context = ($this->context)();
    $row = ($this->service)()->snapshot($context, $this->epg)['programmes'][0];

    expect(($this->service)()->apply($context, $this->epg, [
        ['id' => $row['id'], 'hash' => $row['hash'], 'changes' => ['title' => 'One']],
    ])['status'])->toBe('noop');
});

it('rejects a patch against a row that changed since it was read, all-or-nothing', function () {
    ($this->rebuild)(['One', 'Two']);
    $context = ($this->context)();
    [$first, $second] = ($this->service)()->snapshot($context, $this->epg)['programmes'];

    expect(($this->service)()->apply($context, $this->epg, [
        ['id' => $second['id'], 'hash' => $second['hash'], 'changes' => ['title' => 'Second enriched']],
    ])['status'])->toBe('applied');

    // $second's hash is now outdated, so the whole batch is refused and $first is not written either.
    expect(($this->service)()->apply($context, $this->epg, [
        ['id' => $first['id'], 'hash' => $first['hash'], 'changes' => ['title' => 'First enriched']],
        ['id' => $second['id'], 'hash' => $second['hash'], 'changes' => ['title' => 'Lost update']],
    ])['status'])->toBe('stale')
        ->and(($this->cachedTitles)())->toBe(['One', 'Second enriched']);
});

it('invalidates outstanding patches when the cache is rebuilt, and the rebuild leaves no extra files', function () {
    ($this->rebuild)(['One']);
    $context = ($this->context)();
    $row = ($this->service)()->snapshot($context, $this->epg)['programmes'][0];
    ($this->service)()->apply($context, $this->epg, [['id' => $row['id'], 'hash' => $row['hash'], 'changes' => ['title' => 'Enriched']]]);

    ($this->rebuild)(['Rebuilt']);

    expect(($this->service)()->apply($context, $this->epg, [
        ['id' => $row['id'], 'hash' => $row['hash'], 'changes' => ['title' => 'Too late']],
    ])['status'])->toBe('stale')
        ->and(($this->cachedTitles)())->toBe(['Rebuilt'])
        ->and(($this->cacheFiles)())->toBe([
            "epg-cache/{$this->epg->uuid}/v2/channels.json",
            "epg-cache/{$this->epg->uuid}/v2/metadata.json",
            "epg-cache/{$this->epg->uuid}/v2/programmes.sqlite",
        ]);
});

it('reports unavailable without creating a store when there is no finished cache', function () {
    $context = ($this->context)();

    expect(($this->service)()->snapshot($context, $this->epg)['status'])->toBe('unavailable');

    ($this->rebuild)(['One']);
    $row = ($this->service)()->snapshot($context, $this->epg)['programmes'][0];
    app(EpgCacheService::class)->clearCache($this->epg);

    expect(($this->service)()->apply($context, $this->epg, [
        ['id' => $row['id'], 'hash' => $row['hash'], 'changes' => ['title' => 'Enriched']],
    ])['status'])->toBe('unavailable')
        ->and(($this->cacheFiles)())->toBe([]);
});

it('reports busy while another connection holds the write lock', function () {
    ($this->rebuild)(['One']);
    $context = ($this->context)();
    $row = ($this->service)()->snapshot($context, $this->epg)['programmes'][0];

    $locker = new PDO('sqlite:'.app(EpgCacheService::class)->getProgrammeStorePath($this->epg));
    $locker->exec('BEGIN EXCLUSIVE');

    $service = new class(app(EpgCacheService::class)) extends EpgCacheEnrichmentService
    {
        protected int $busyTimeoutMs = 50;
    };

    try {
        expect($service->apply($context, $this->epg, [
            ['id' => $row['id'], 'hash' => $row['hash'], 'changes' => ['title' => 'Enriched']],
        ])['status'])->toBe('busy');
    } finally {
        $locker->exec('ROLLBACK');
    }

    expect(($this->cachedTitles)())->toBe(['One']);
});

it('reports busy promptly while another process holds the sanctioned replacement lock', function () {
    ($this->rebuild)(['One']);
    $path = app(EpgCacheService::class)->getProgrammeStorePath($this->epg);
    $holder = ($this->lockProcess)('exclusive', $path, 1500);
    $holder->start();
    expect($holder->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'locked')))->toBeTrue();

    $service = new class(app(EpgCacheService::class)) extends EpgCacheEnrichmentService
    {
        protected int $busyTimeoutMs = 50;
    };
    $startedAt = microtime(true);

    try {
        $result = $service->guardedSnapshot(($this->context)(), $this->epg);
    } finally {
        $holder->stop(0.1);
    }

    expect($result['status'])->toBe('busy')
        ->and((microtime(true) - $startedAt) * 1000)->toBeLessThan(500);
});

it('bounds sanctioned rebuild locking and leaves the published store untouched on contention', function () {
    ($this->rebuild)(['Original']);
    ($this->writeXml)(['Replacement']);
    $path = app(EpgCacheService::class)->getProgrammeStorePath($this->epg);
    $holder = ($this->lockProcess)('shared', $path, 1500);
    $holder->start();
    expect($holder->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'locked')))->toBeTrue();

    $callbackRan = false;
    $startedAt = microtime(true);
    try {
        expect(fn () => EpgProgrammeStore::withExclusivePathLock(
            $path,
            function () use (&$callbackRan): void {
                $callbackRan = true;
            },
            50,
        ))->toThrow(EpgProgrammeStoreBusyException::class);

        $cacheService = new class extends EpgCacheService
        {
            protected int $programmeStoreLockTimeoutMs = 50;
        };
        expect($cacheService->cacheEpgData($this->epg->fresh()))->toBeFalse();
    } finally {
        $holder->stop(0.1);
    }

    expect($callbackRan)->toBeFalse()
        ->and((microtime(true) - $startedAt) * 1000)->toBeLessThan(750)
        ->and(($this->cachedTitles)())->toBe(['Original']);
});

it('creates the stable lock directory safely when processes race', function () {
    ($this->rebuild)(['One']);
    $path = app(EpgCacheService::class)->getProgrammeStorePath($this->epg);
    Storage::disk('local')->deleteDirectory('epg-cache/.programme-store-locks');

    $processes = array_map(
        fn (): Process => ($this->lockProcess)('shared', $path, 100),
        range(1, 6),
    );
    foreach ($processes as $process) {
        $process->start();
    }

    foreach ($processes as $process) {
        $process->wait();
        expect($process->getExitCode())->toBe(0)
            ->and($process->getErrorOutput())->toBe('');
    }
});

it('rejects malformed patches, naming the offending patch and field', function (array $patch, string $field) {
    ($this->rebuild)(['One']);
    $context = ($this->context)();
    $row = ($this->service)()->snapshot($context, $this->epg)['programmes'][0];
    $valid = ['id' => $row['id'], 'hash' => $row['hash'], 'changes' => ['title' => 'Fine']];

    expect(($this->service)()->apply($context, $this->epg, [
        $valid,
        array_merge(['id' => $row['id'] + 1, 'hash' => $row['hash']], $patch),
    ]))->toBe(['status' => 'invalid_request', 'index' => 1, 'field' => $field])
        ->and(($this->cachedTitles)())->toBe(['One']);
})->with([
    'non-whitelisted field' => [['changes' => ['channel' => 'other']], 'channel'],
    'empty changes' => [['changes' => []], 'changes'],
    'wrong type' => [['changes' => ['new' => 'yes']], 'new'],
    'oversized string' => [['changes' => ['desc' => str_repeat('a', 10001)]], 'desc'],
    'invalid icon url' => [['changes' => ['icon' => 'not a url']], 'icon'],
    'file scheme icon' => [['changes' => ['icon' => 'file:///etc/passwd']], 'icon'],
    'gopher scheme url' => [['changes' => ['urls' => [['system' => 'imdb', 'value' => 'gopher://127.0.0.1:6379/_x']]]], 'urls'],
    'url entry with extra key' => [['changes' => ['urls' => [['system' => 'imdb', 'value' => 'https://imdb.com/t', 'x' => 1]]]], 'urls'],
    'image with unknown key' => [['changes' => ['images' => [['url' => 'https://example.com/a.jpg', 'bogus' => 1]]]], 'images'],
    'url-only image' => [['changes' => ['images' => [['url' => 'https://image.tmdb.org/t/p/w500/a.jpg']]]], 'images'],
    'image with array width' => [['changes' => ['images' => [[
        'url' => 'https://image.tmdb.org/a.jpg', 'type' => 'poster', 'width' => [1], 'height' => 0, 'orient' => 'P', 'size' => 1,
    ]]]], 'images'],
    'replacement and append images together' => [['changes' => ['images' => [], 'images_append' => []]], 'images_append'],
    'non-canonical episode_nums' => [['changes' => ['episode_nums' => [['value' => '1.2.0/1']]]], 'episode_nums'],
    'missing hash' => [['hash' => null, 'changes' => ['title' => 'x']], 'hash'],
]);

it('accepts full-shape artwork from hosts outside the playlist domain allowlist, and serves it as XMLTV', function () {
    config(['dev.allowed_playlist_domains' => 'https://my-provider.example/*']);
    ($this->rebuild)(['One']);
    $playlist = Playlist::factory()->for($this->user)->create(['dummy_epg' => false]);
    $epgChannel = EpgChannel::factory()->for($this->user)->for($this->epg)->create(['channel_id' => 'enrich.a']);
    Channel::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'channel' => 1,
        'epg_channel_id' => $epgChannel->id,
    ]);

    $context = ($this->context)();
    $row = ($this->service)()->snapshot($context, $this->epg)['programmes'][0];
    $image = ['url' => 'https://image.tmdb.org/t/p/w500/a.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750, 'orient' => 'P', 'size' => 2];

    expect(($this->service)()->apply($context, $this->epg, [
        ['id' => $row['id'], 'hash' => $row['hash'], 'changes' => ['title' => 'Enriched', 'icon' => 'https://image.tmdb.org/icon.png', 'images' => [$image]]],
    ])['status'])->toBe('applied');

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");
    $response->assertOk();

    $document = new DOMDocument;
    expect($document->loadXML(gzdecode($response->getContent())))->toBeTrue();
    $xpath = new DOMXPath($document);
    expect($xpath->query('//programme[title="Enriched"]/image[@type="poster"][@orient="P"][@size="3"][text()="https://image.tmdb.org/t/p/w500/a.jpg"]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Enriched"]/icon[@src="https://image.tmdb.org/t/p/w500/a.jpg"][@width="500"][@height="750"][not(@type)][not(@orient)][not(@size)]'))->toHaveCount(1)
        ->and($xpath->query('//programme[title="Enriched"]/icon[@type or @orient or @size]'))->toHaveCount(0);
});

it('imports exact serialized enricher output, persists it, and exports XMLTV for Emby', function () {
    $enricherOutputPath = getenv('CROSS_REPO_ENRICHER_OUTPUT');
    $coreXmlOutputPath = getenv('CROSS_REPO_CORE_XML');
    if (! is_string($enricherOutputPath) || $enricherOutputPath === '' || ! is_string($coreXmlOutputPath) || $coreXmlOutputPath === '') {
        $this->markTestSkipped('Run through cross-repo-artwork-contract/run.sh to provide the serialized Enricher and Core artifacts.');
    }

    $serializedEnricherOutput = file_get_contents($enricherOutputPath);
    expect($serializedEnricherOutput)->toBeString()->not->toBe('');
    $enricherOutput = json_decode($serializedEnricherOutput, true, flags: JSON_THROW_ON_ERROR);
    expect($enricherOutput)->toHaveKeys(['schema_version', 'programme_before', 'host_changes', 'programme_after'])
        ->and($enricherOutput['schema_version'])->toBe(1)
        ->and($enricherOutput['host_changes'])->toBeArray()->not->toBeEmpty();

    $sourceTitle = $enricherOutput['programme_before']['title'] ?? null;
    expect($sourceTitle)->toBeString()->not->toBe('');
    ($this->rebuild)([$sourceTitle]);
    $playlist = Playlist::factory()->for($this->user)->create(['dummy_epg' => false]);
    $epgChannel = EpgChannel::factory()->for($this->user)->for($this->epg)->create(['channel_id' => 'enrich.a']);
    Channel::factory()->for($this->user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'channel' => 1,
        'epg_channel_id' => $epgChannel->id,
    ]);

    $context = ($this->context)();
    $row = ($this->service)()->snapshot($context, $this->epg)['programmes'][0];
    $applyResult = ($this->service)()->apply($context, $this->epg, [[
        'id' => $row['id'],
        'hash' => $row['hash'],
        'changes' => $enricherOutput['host_changes'],
    ]]);

    $expectedStatus = getenv('CROSS_REPO_EXPECT_CORE_STATUS') ?: 'applied';
    expect($applyResult['status'])->toBe($expectedStatus);
    if ($expectedStatus !== 'applied') {
        return;
    }

    $stored = ($this->service)()->snapshot($context, $this->epg)['programmes'][0]['programme'];
    expect($stored['images'] ?? null)->toBe($enricherOutput['host_changes']['images'] ?? null);

    $response = $this->get("/{$playlist->uuid}/epg.xml.gz");
    $response->assertOk();
    $xml = gzdecode($response->getContent());
    expect($xml)->toBeString()->not->toBe('')
        ->and(file_put_contents($coreXmlOutputPath, $xml))->toBe(strlen($xml));
});

it('appends validated artwork without replacing legacy untyped source images', function () {
    ($this->rebuild)(['One']);
    $context = ($this->context)();
    $path = app(EpgCacheService::class)->getProgrammeStorePath($this->epg);
    $pdo = new PDO('sqlite:'.$path);
    $legacyImage = ['url' => 'https://source.example/legacy-untyped.jpg'];
    $data = json_decode((string) $pdo->query('SELECT data FROM programmes LIMIT 1')->fetchColumn(), true);
    $data['images'] = [$legacyImage];
    $pdo->prepare('UPDATE programmes SET data = ?')->execute([json_encode($data)]);

    $row = ($this->service)()->snapshot($context, $this->epg)['programmes'][0];
    $poster = ['url' => 'https://image.tmdb.org/t/p/w500/poster.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750, 'orient' => 'P', 'size' => 2];
    $patch = [['id' => $row['id'], 'hash' => $row['hash'], 'changes' => ['images_append' => [$poster]]]];

    expect(($this->service)()->apply($context, $this->epg, $patch)['status'])->toBe('applied');
    $stored = ($this->service)()->snapshot($context, $this->epg)['programmes'][0];
    expect($stored['programme']['images'])->toBe([$legacyImage, $poster]);

    expect(($this->service)()->apply($context, $this->epg, [[
        'id' => $stored['id'], 'hash' => $stored['hash'], 'changes' => ['images_append' => [$poster]],
    ]])['status'])->toBe('noop')
        ->and(($this->service)()->snapshot($context, $this->epg)['programmes'][0]['programme']['images'])->toBe([$legacyImage, $poster]);
});

it('keeps append-only artwork patches evidence-neutral for successor guarded applies', function () {
    ($this->rebuild)(['One']);
    $context = ($this->context)();
    $initial = ($this->service)()->guardedSnapshot($context, $this->epg);
    $row = $initial['programmes'][0];
    $poster = ['url' => 'https://image.tmdb.org/t/p/w500/poster.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750, 'orient' => 'P', 'size' => 2];

    $result = ($this->service)()->guardedApply($context, $this->epg, [[
        'id' => $row['id'], 'hash' => $row['hash'], 'changes' => ['images_append' => [$poster]],
    ]], $initial['evidence']);

    expect($result['status'])->toBe('applied')
        ->and($result['evidence'] ?? null)->toBeString()->not->toBe('');
});

it('rejects duplicate ids and oversized batches', function () {
    ($this->rebuild)(['One']);
    $context = ($this->context)();
    $row = ($this->service)()->snapshot($context, $this->epg)['programmes'][0];
    $patch = ['id' => $row['id'], 'hash' => $row['hash'], 'changes' => ['title' => 'x']];

    expect(($this->service)()->apply($context, $this->epg, [$patch, $patch])['status'])->toBe('invalid_request')
        ->and(($this->service)()->apply($context, $this->epg, array_fill(0, EpgCacheEnrichmentService::MAX_PATCHES + 1, $patch))['status'])->toBe('invalid_request');
});

it('denies plugins without the capability, disabled plugins and other users', function () {
    ($this->rebuild)(['One']);

    expect(($this->service)()->snapshot(($this->context)(['capabilities' => []]), $this->epg)['status'])->toBe('denied')
        ->and(($this->service)()->snapshot(($this->context)(['enabled' => false]), $this->epg)['status'])->toBe('denied')
        ->and(($this->service)()->snapshot(($this->context)(user: User::factory()->create()), $this->epg)['status'])->toBe('denied');
});

it('refuses to write to a store that a rebuild replaced after it was opened', function () {
    ($this->rebuild)(['One']);
    $path = app(EpgCacheService::class)->getProgrammeStorePath($this->epg);
    $store = EpgProgrammeStore::openExisting($path);
    [$id, $row] = [array_key_first($page = $store->readPage(0, 1)), $page[array_key_first($page)]];

    // Simulate a rebuild renaming a new store over the path mid-request.
    copy($path, $path.'.new');
    rename($path.'.new', $path);

    expect($store->updateRows([$id => $row['hash']], [$id => ['title' => 'Lost']]))->toBeNull();
    $store->close();
});

it('rejects guarded apply after a rebuild preserves observed rows and adds a conflict', function () {
    ($this->rebuild)(['Seed', 'Target']);
    $context = ($this->context)();
    $snapshot = ($this->service)()->guardedSnapshot($context, $this->epg);
    $target = $snapshot['programmes'][1];

    ($this->rebuild)(['Seed', 'Target', 'Late conflict']);

    expect(($this->service)()->guardedApply($context, $this->epg, [[
        'id' => $target['id'], 'hash' => $target['hash'], 'changes' => ['title' => 'Unsafe propagation'],
    ]], $snapshot['evidence'])['status'])->toBe('stale')
        ->and(($this->cachedTitles)())->toBe(['Seed', 'Target', 'Late conflict']);
});

it('keeps one guarded census across pages and rolls artwork writes forward by returned evidence', function () {
    ($this->rebuild)(['One', 'Two', 'Three']);
    $context = ($this->context)();
    $service = ($this->service)();

    $first = $service->guardedSnapshot($context, $this->epg, limit: 1);
    $second = $service->guardedSnapshot($context, $this->epg, $first['next'], 1, $first['evidence']);
    $third = $service->guardedSnapshot($context, $this->epg, $second['next'], 1, $second['evidence']);

    expect($first['status'])->toBe('ok')
        ->and($second['status'])->toBe('ok')
        ->and($third['status'])->toBe('ok')
        ->and($third['next'])->toBeNull();

    $firstApply = $service->guardedApply($context, $this->epg, [[
        'id' => $first['programmes'][0]['id'],
        'hash' => $first['programmes'][0]['hash'],
        'changes' => ['icon' => 'https://images.example/one.jpg'],
    ]], $third['evidence']);
    $secondApply = $service->guardedApply($context, $this->epg, [[
        'id' => $second['programmes'][0]['id'],
        'hash' => $second['programmes'][0]['hash'],
        'changes' => ['icon' => 'https://images.example/two.jpg'],
    ]], $firstApply['evidence']);
    $current = $service->guardedSnapshot($context, $this->epg, evidence: $secondApply['evidence']);

    expect($firstApply['status'])->toBe('applied')
        ->and($firstApply)->toHaveKey('evidence')
        ->and($secondApply['status'])->toBe('applied')
        ->and($secondApply)->toHaveKey('evidence')
        ->and($current['status'])->toBe('ok')
        ->and($current['programmes'][0]['programme']['icon'])->toBe('https://images.example/one.jpg')
        ->and($current['programmes'][1]['programme']['icon'])->toBe('https://images.example/two.jpg');
});

it('rejects missing malformed cross-plugin and cross-source evidence tokens', function () {
    ($this->rebuild)(['One', 'Two']);
    $context = ($this->context)();
    $snapshot = ($this->service)()->guardedSnapshot($context, $this->epg, limit: 1);
    $otherEpg = Epg::factory()->for($this->user)->create(['url' => 'https://example.com/other.xml']);

    expect(($this->service)()->guardedSnapshot($context, $this->epg, $snapshot['next'], 1)['status'])->toBe('invalid_request')
        ->and(($this->service)()->guardedSnapshot($context, $this->epg, 0, 1, 'not-a-token')['status'])->toBe('invalid_request')
        ->and(($this->service)()->guardedSnapshot(($this->context)(), $this->epg, 0, 1, $snapshot['evidence'])['status'])->toBe('invalid_request')
        ->and(($this->service)()->guardedSnapshot($context, $otherEpg, 0, 1, $snapshot['evidence'])['status'])->toBe('invalid_request')
        ->and(($this->service)()->guardedApply($context, $this->epg, [[
            'id' => $snapshot['programmes'][0]['id'],
            'hash' => $snapshot['programmes'][0]['hash'],
            'changes' => ['icon' => 'https://images.example/one.jpg'],
        ]], '')['status'])->toBe('invalid_request');
});

it('rejects refreshed row hashes when unrelated legacy mutation invalidated the census', function () {
    ($this->rebuild)(['Seed', 'Target']);
    $context = ($this->context)();
    $service = ($this->service)();
    $guarded = $service->guardedSnapshot($context, $this->epg);

    expect($service->apply($context, $this->epg, [[
        'id' => $guarded['programmes'][0]['id'],
        'hash' => $guarded['programmes'][0]['hash'],
        'changes' => ['title' => 'New conflicting seed'],
    ]])['status'])->toBe('applied');

    $refreshedTarget = $service->snapshot($context, $this->epg)['programmes'][1];
    expect($service->guardedApply($context, $this->epg, [[
        'id' => $refreshedTarget['id'],
        'hash' => $refreshedTarget['hash'],
        'changes' => ['icon' => 'https://images.example/unsafe.jpg'],
    ]], $guarded['evidence'])['status'])->toBe('stale')
        ->and(($this->cachedTitles)())->toBe(['New conflicting seed', 'Target']);
});

it('does not roll evidence forward after its own identity-changing write', function () {
    ($this->rebuild)(['One', 'Two']);
    $context = ($this->context)();
    $service = ($this->service)();
    $snapshot = $service->guardedSnapshot($context, $this->epg);

    $changed = $service->guardedApply($context, $this->epg, [[
        'id' => $snapshot['programmes'][0]['id'],
        'hash' => $snapshot['programmes'][0]['hash'],
        'changes' => ['title' => 'Identity changed'],
    ]], $snapshot['evidence']);
    $later = $service->guardedApply($context, $this->epg, [[
        'id' => $snapshot['programmes'][1]['id'],
        'hash' => $snapshot['programmes'][1]['hash'],
        'changes' => ['icon' => 'https://images.example/two.jpg'],
    ]], $snapshot['evidence']);

    expect($changed['status'])->toBe('applied')
        ->and($changed)->not->toHaveKey('evidence')
        ->and($later['status'])->toBe('stale')
        ->and(($this->cachedTitles)())->toBe(['Identity changed', 'Two']);
});

it('keeps evidence current across guarded noops', function () {
    ($this->rebuild)(['One']);
    $context = ($this->context)();
    $service = ($this->service)();
    $snapshot = $service->guardedSnapshot($context, $this->epg);
    $patch = [[
        'id' => $snapshot['programmes'][0]['id'],
        'hash' => $snapshot['programmes'][0]['hash'],
        'changes' => ['icon' => ''],
    ]];

    $first = $service->guardedApply($context, $this->epg, $patch, $snapshot['evidence']);
    $second = $service->guardedApply($context, $this->epg, $patch, $snapshot['evidence']);

    expect($first['status'])->toBe('noop')
        ->and($first)->toHaveKey('evidence')
        ->and($second['status'])->toBe('noop');
});

it('leaves stores without evidence metadata untouched and legacy-compatible', function () {
    ($this->rebuild)(['One']);
    $context = ($this->context)();
    $guarded = ($this->service)()->guardedSnapshot($context, $this->epg);
    $path = app(EpgCacheService::class)->getProgrammeStorePath($this->epg);
    $pdo = new PDO('sqlite:'.$path);
    $pdo->exec('DROP TABLE enrichment_state');
    $pdo = null;

    expect(($this->service)()->guardedSnapshot($context, $this->epg)['status'])->toBe('unsupported')
        ->and(($this->service)()->guardedApply($context, $this->epg, [[
            'id' => $guarded['programmes'][0]['id'],
            'hash' => $guarded['programmes'][0]['hash'],
            'changes' => ['icon' => 'https://images.example/one.jpg'],
        ]], $guarded['evidence'])['status'])->toBe('unsupported');

    $pdo = new PDO('sqlite:'.$path);
    $hasState = (int) $pdo->query("SELECT COUNT(*) FROM sqlite_schema WHERE type = 'table' AND name = 'enrichment_state'")->fetchColumn();
    $pdo = null;
    $legacy = ($this->service)()->snapshot($context, $this->epg);

    expect($hasState)->toBe(0)
        ->and($legacy['status'])->toBe('ok')
        ->and(($this->service)()->apply($context, $this->epg, [[
            'id' => $legacy['programmes'][0]['id'],
            'hash' => $legacy['programmes'][0]['hash'],
            'changes' => ['title' => 'Legacy still works'],
        ]])['status'])->toBe('applied')
        ->and(($this->cachedTitles)())->toBe(['Legacy still works']);
});

it('accepts five hundred guarded patches and rejects five hundred and one', function () {
    ($this->rebuild)(array_map(fn (int $index): string => "Programme {$index}", range(1, 500)));
    $context = ($this->context)();
    $service = ($this->service)();
    $snapshot = $service->guardedSnapshot($context, $this->epg);
    $patches = array_map(fn (array $row): array => [
        'id' => $row['id'],
        'hash' => $row['hash'],
        'changes' => ['title' => $row['programme']['title']],
    ], $snapshot['programmes']);

    expect($snapshot['next'])->toBeNull()
        ->and($service->guardedApply($context, $this->epg, $patches, $snapshot['evidence'])['status'])->toBe('noop');

    $patches[] = $patches[0];
    expect($service->guardedApply($context, $this->epg, $patches, $snapshot['evidence'])['status'])->toBe('invalid_request');
});
it('rejects a later census page after the source was replaced', function () {
    ($this->rebuild)(['One', 'Two', 'Three']);
    $context = ($this->context)();
    $first = ($this->service)()->guardedSnapshot($context, $this->epg, limit: 1);

    ($this->rebuild)(['One', 'Two', 'Three']);

    expect(($this->service)()->guardedSnapshot(
        $context,
        $this->epg,
        $first['next'],
        1,
        $first['evidence'],
    )['status'])->toBe('stale');
});
