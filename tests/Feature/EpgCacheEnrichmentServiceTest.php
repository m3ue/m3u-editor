<?php

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
    expect((new DOMXPath($document))->query('//programme[title="Enriched"]/icon[@type="poster" and @width="500" and @height="750" and @orient="P" and @size="2"]'))->toHaveCount(1);
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
