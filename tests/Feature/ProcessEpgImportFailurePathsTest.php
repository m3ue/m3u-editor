<?php

/**
 * Drives ProcessEpgImport and its chunk/complete chain against synthetic
 * XMLTV files, covering the multi-chunk happy path and the failure paths
 * that must leave a previously imported EPG intact.
 */

use App\Enums\Status;
use App\Jobs\GenerateEpgCache;
use App\Jobs\ProcessEpgImport;
use App\Jobs\ProcessEpgImportChunk;
use App\Jobs\ProcessEpgImportComplete;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Job;
use App\Models\User;
use App\Services\SchedulesDirectService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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

    // Runs the import, then the chunk/complete chain exactly as it was dispatched
    $this->runImport = function () {
        (new ProcessEpgImport($this->epg->fresh(), force: true))->handle(app(SchedulesDirectService::class));

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

/**
 * Every 40th channel reuses the id "dup.test", so duplicates land in each 50-channel chunk.
 */
function writeXmltv(string $path, int $channelCount): void
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?><tv>';
    foreach (range(1, $channelCount) as $i) {
        $channelId = $i % 40 === 0 ? 'dup.test' : "ch{$i}.test";
        $xml .= "<channel id=\"{$channelId}\"><display-name lang=\"en\">Channel {$i}</display-name></channel>";
    }
    $xml .= '<programme start="20260101000000 +0000" stop="20260101010000 +0000" channel="ch1.test"><title>Show</title></programme>';
    $xml .= '</tv>';

    file_put_contents($path, $xml);
}

it('imports every channel of a feed spanning several 50-channel chunks and drops channels the feed no longer lists', function () {
    $stale = EpgChannel::factory()->for($this->user)->for($this->epg)->create(['import_batch_no' => 'previous-batch']);
    writeXmltv($this->xmltvPath, 120);

    ($this->runImport)();

    // A completed import hands the EPG straight over to cache generation
    $epg = $this->epg->fresh();
    expect($epg->errors)->toBeNull()
        ->and($epg->synced)->not->toBeNull()
        ->and($epg->channel_count)->toBe(120)
        ->and($epg->programme_count)->toBe(1)
        ->and($epg->channels()->count())->toBe(120)
        ->and($epg->channels()->where('channel_id', 'dup.test')->pluck('name')->sort()->values()->all())
        ->toBe(['Channel 120', 'Channel 40', 'Channel 80'])
        ->and(EpgChannel::find($stale->id))->toBeNull();
    Bus::assertDispatched(GenerateEpgCache::class);
});

it('keeps channel rows and source ids stable when the same feed is imported again', function () {
    writeXmltv($this->xmltvPath, 120);
    ($this->runImport)();
    $firstImport = $this->epg->channels()->pluck('source_id', 'id')->all();

    Bus::fake();
    ($this->runImport)();

    expect($this->epg->channels()->pluck('source_id', 'id')->all())->toEqual($firstImport);
});

it('fails without touching existing channels when the XMLTV file cannot be read', function () {
    $existing = EpgChannel::factory()->for($this->user)->for($this->epg)->create(['import_batch_no' => 'previous-batch']);
    $this->epg->updateQuietly(['url' => null]);

    ($this->runImport)();

    $epg = $this->epg->fresh();
    expect($epg->status)->toBe(Status::Failed)
        ->and($epg->errors)->toContain('Invalid EPG file')
        ->and($epg->processing)->toBeFalse()
        ->and(EpgChannel::find($existing->id))->not->toBeNull()
        ->and(Job::query()->count())->toBe(0);
    Bus::assertNotDispatched(ProcessEpgImportComplete::class);
});

it('fails and keeps the existing channels when the XMLTV download is truncated', function () {
    $existing = EpgChannel::factory()->for($this->user)->for($this->epg)->create(['import_batch_no' => 'previous-batch']);
    file_put_contents($this->xmltvPath, '<?xml version="1.0"?><tv><channel id="ch1.test"><display-na');

    ($this->runImport)();

    $epg = $this->epg->fresh();
    expect($epg->status)->toBe(Status::Failed)
        ->and($epg->errors)->toContain('parser error')
        ->and($epg->processing)->toBeFalse()
        ->and(EpgChannel::find($existing->id))->not->toBeNull();
    Bus::assertNotDispatched(ProcessEpgImportComplete::class);
});

it('fails and keeps the existing channels when the source returns an HTML error page instead of XMLTV', function () {
    $existing = EpgChannel::factory()->for($this->user)->for($this->epg)->create(['import_batch_no' => 'previous-batch']);
    file_put_contents($this->xmltvPath, '<html><body><h1>503 Service Unavailable</h1></body></html>');

    ($this->runImport)();

    $epg = $this->epg->fresh();
    expect($epg->status)->toBe(Status::Failed)
        ->and($epg->errors)->toContain('No channels found in the EPG file')
        ->and($epg->processing)->toBeFalse()
        ->and(EpgChannel::find($existing->id))->not->toBeNull();
    Bus::assertNotDispatched(ProcessEpgImportComplete::class);
});

it('schedules a resync when an HTML error page replaces a previously imported EPG', function () {
    EpgChannel::factory()->for($this->user)->for($this->epg)->create(['import_batch_no' => 'previous-batch']);
    $this->epg->updateQuietly(['auto_resync_on_failure' => true, 'auto_resync_retries' => 3, 'resync_attempt' => 0]);
    file_put_contents($this->xmltvPath, '<html><body><h1>503 Service Unavailable</h1></body></html>');

    ($this->runImport)();

    expect($this->epg->fresh()->resync_attempt)->toBe(1);
    Bus::assertDispatched(ProcessEpgImport::class);
});

it('still completes an empty feed for an EPG that has no channels yet', function () {
    file_put_contents($this->xmltvPath, '<?xml version="1.0"?><tv></tv>');

    ($this->runImport)();

    $epg = $this->epg->fresh();
    expect($epg->errors)->toBeNull()
        ->and($epg->channel_count)->toBe(0)
        ->and($epg->synced)->not->toBeNull();
    Bus::assertDispatched(ProcessEpgImportComplete::class);
});

describe('URL download', function () {
    beforeEach(function () {
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->epg->updateQuietly(['url' => 'http://epg.example.test/guide.xml']);
        EpgChannel::factory()->for($this->user)->for($this->epg)->create(['import_batch_no' => 'previous-batch']);
        Storage::disk('local')->put($this->epg->file_path, 'last good guide');
    });

    it('replaces the guide file once the new download parses as XMLTV', function () {
        writeXmltv($this->xmltvPath, 3);
        Http::fake(['epg.example.test/*' => Http::response(file_get_contents($this->xmltvPath))]);

        ($this->runImport)();

        expect(Storage::disk('local')->get($this->epg->file_path))->toBe(file_get_contents($this->xmltvPath))
            ->and(Storage::disk('local')->exists("{$this->epg->file_path}.download"))->toBeFalse()
            ->and($this->epg->fresh()->channels()->count())->toBe(3);
    });

    it('keeps the last good guide file when the download fails or is not XMLTV', function (Closure $response) {
        Http::fake(['epg.example.test/*' => $response]);

        ($this->runImport)();

        $epg = $this->epg->fresh();
        expect($epg->status)->toBe(Status::Failed)
            ->and($epg->channels()->count())->toBe(1)
            ->and(Storage::disk('local')->get($epg->file_path))->toBe('last good guide')
            ->and(Storage::disk('local')->exists("{$epg->file_path}.download"))->toBeFalse();
    })->with([
        'HTML error page' => fn () => fn () => Http::response('<html><body><h1>Maintenance</h1></body></html>'),
        'truncated download' => fn () => fn () => Http::response('<?xml version="1.0"?><tv><channel id="ch1.test"><display-na'),
        'HTTP 503' => fn () => fn () => Http::response('<html><body>503</body></html>', 503),
    ]);
});
