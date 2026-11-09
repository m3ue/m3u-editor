<?php

/**
 * A channel id of the literal string "0" is a valid, nonempty XMLTV identifier (the DTD only
 * requires a nonempty CDATA value), but several `if ($channelId)` / `! $channelId` guards treat
 * it as empty under PHP truthiness. This drives the same real ProcessEpgImport chain used by
 * ProcessEpgImportFailurePathsTest.php, plus the EpgCacheService single-pass cache writer, to
 * confirm channel/programme id "0" survives both.
 */

use App\Jobs\ProcessEpgImport;
use App\Jobs\ProcessEpgImportChunk;
use App\Jobs\ProcessEpgImportComplete;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\EpgCacheService;
use App\Services\SchedulesDirectService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid().'.sqlite';
    touch($this->tempJobsDb);
    config(['database.connections.jobs.database' => $this->tempJobsDb]);
    DB::purge('jobs');

    $migration = require database_path('migrations/2025_02_13_215803_create_jobs_table.php');
    $migration->up();

    Bus::fake();

    $this->user = User::factory()->create();
    $this->xmltvPath = sys_get_temp_dir().'/epg_import_'.uniqid().'.xml';
    $this->epg = Epg::factory()->for($this->user)->createQuietly([
        'url' => $this->xmltvPath,
        'uploads' => null,
        'auto_resync_on_failure' => false,
    ]);

    $this->runImport = function () {
        $import = new ProcessEpgImport($this->epg->fresh(), force: true);
        $import->handle(app(SchedulesDirectService::class));

        $first = Bus::dispatched(ProcessEpgImportChunk::class)->first()
            ?? Bus::dispatched(ProcessEpgImportComplete::class)->first();
        if ($first === null) {
            return;
        }

        foreach ([$first, ...array_map('unserialize', $first->chained)] as $job) {
            $job->handle();
        }
    };
});

afterEach(function () {
    DB::purge('jobs');
    config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);

    foreach ([$this->tempJobsDb ?? null, $this->xmltvPath ?? null] as $path) {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }
});

it('imports a channel whose xmltv id is the literal string "0"', function () {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <channel id="0"><display-name>Numeric channel</display-name></channel>
  <channel id="one.test"><display-name>Control channel</display-name></channel>
  <programme channel="0" start="20261022120000 +0000" stop="20261022130000 +0000"><title>Numeric programme</title></programme>
  <programme channel="one.test" start="20261022120000 +0000" stop="20261022130000 +0000"><title>Control programme</title></programme>
</tv>
XML;
    file_put_contents($this->xmltvPath, $xml);

    ($this->runImport)();

    $epg = $this->epg->fresh();
    expect($epg->errors)->toBeNull()
        ->and($epg->channel_count)->toBe(2)
        ->and($epg->channels()->where('channel_id', '0')->exists())->toBeTrue()
        ->and($epg->channels()->where('channel_id', 'one.test')->exists())->toBeTrue();
});

it('caches a programme whose xmltv channel id is the literal string "0"', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['dummy_epg' => false]);
    $epg = Epg::factory()->for($user)->create(['url' => 'https://example.com/zero.xml']);

    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'channel_id' => '0',
        'display_name' => 'Numeric channel',
    ]);
    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
    ]);

    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <channel id="0"><display-name>Numeric channel</display-name></channel>
  <programme channel="0" start="20261022120000 +0000" stop="20261022130000 +0000"><title>Numeric programme</title></programme>
</tv>
XML;
    Storage::disk('local')->put($epg->file_path, gzencode($xml));

    expect(app(EpgCacheService::class)->cacheEpgData($epg))->toBeTrue();

    $programmes = app(EpgCacheService::class)->getCachedProgrammes($epg, '2026-10-22', ['0']);

    expect($programmes['0'] ?? [])->toHaveCount(1)
        ->and($programmes['0'][0]['title'])->toBe('Numeric programme');
});
