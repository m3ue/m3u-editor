<?php

use App\Enums\EpgSourceType;
use App\Jobs\ProcessEpgImport;
use App\Jobs\ProcessEpgImportChunk;
use App\Jobs\ProcessEpgImportComplete;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\Job;
use App\Models\User;
use App\Services\SchedulesDirectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
    Sleep::fake();
    Storage::fake('local');
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->makeEpg = fn (array $lineupIds, array $attributes = []) => Epg::factory()->create([
        'user_id' => $this->user->id,
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => 'test@example.com',
        'sd_password' => 'password',
        'sd_token' => 'valid-token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_lineup_ids' => $lineupIds,
        'sd_days_to_import' => 1,
        ...$attributes,
    ]);
});

/**
 * @return array{map: array<int, array<string, string>>, stations: array<int, array<string, string>>}
 */
function sdLineupResponse(array $stationIds): array
{
    return [
        'map' => array_map(fn (string $id) => ['stationID' => $id, 'channel' => $id], $stationIds),
        'stations' => array_map(fn (string $id) => ['stationID' => $id, 'name' => "Station {$id}", 'callsign' => "S{$id}"], $stationIds),
    ];
}

it('merges stations from every selected lineup and keeps shared stations once', function () {
    $epg = ($this->makeEpg)(['USA-A', 'USA-B']);

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-A' => Http::response(sdLineupResponse(['1', '2'])),
        'json.schedulesdirect.org/20141201/lineups/USA-B' => Http::response(sdLineupResponse(['2', '3'])),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([['stationID' => '1', 'programs' => []]]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([]),
    ]);

    app(SchedulesDirectService::class)->syncEpgData($epg);

    expect($epg->fresh()->sd_station_ids)->toBe(['1', '2', '3']);
});

it('rejects an HTTP-success SchedulesDirect API error before it replaces an existing lineup file', function () {
    $epg = ($this->makeEpg)(['USA-EXISTING'], ['sd_station_ids' => ['old-station']]);
    Storage::disk('local')->put($epg->file_path, 'existing XMLTV data');

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-EXISTING' => Http::response([
            'code' => 3000,
            'message' => 'Provider unavailable',
        ]),
    ]);

    expect(fn () => app(SchedulesDirectService::class)->syncEpgData($epg))
        ->toThrow(Exception::class, 'Provider unavailable');

    expect($epg->fresh()->sd_station_ids)->toBe(['old-station'])
        ->and(Storage::disk('local')->get($epg->file_path))->toBe('existing XMLTV data');
});

it('rejects malformed JSON without replacing an existing lineup file', function () {
    $epg = ($this->makeEpg)(['USA-MALFORMED'], ['sd_station_ids' => ['old-station']]);
    Storage::disk('local')->put($epg->file_path, 'existing XMLTV data');

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-MALFORMED' => Http::response('{', 200, ['Content-Type' => 'application/json']),
    ]);

    expect(fn () => app(SchedulesDirectService::class)->syncEpgData($epg))
        ->toThrow(Exception::class, 'invalid JSON');

    expect($epg->fresh()->sd_station_ids)->toBe(['old-station'])
        ->and(Storage::disk('local')->get($epg->file_path))->toBe('existing XMLTV data');
});

it('rejects an incomplete lineup without replacing an existing lineup file', function () {
    $epg = ($this->makeEpg)(['USA-BROKEN'], ['sd_station_ids' => ['old-station']]);
    Storage::disk('local')->put($epg->file_path, 'existing XMLTV data');

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-BROKEN' => Http::response(['map' => []]),
    ]);

    expect(fn () => app(SchedulesDirectService::class)->syncEpgData($epg))
        ->toThrow(Exception::class, 'invalid lineup structure');

    expect($epg->fresh()->sd_station_ids)->toBe(['old-station'])
        ->and(Storage::disk('local')->get($epg->file_path))->toBe('existing XMLTV data');
});

it('rejects a malformed lineup alongside a valid lineup without partially replacing existing stations', function () {
    $epg = ($this->makeEpg)(['USA-GOOD', 'USA-BROKEN'], ['sd_station_ids' => ['old-station']]);
    Storage::disk('local')->put($epg->file_path, 'existing XMLTV data');

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-GOOD' => Http::response(sdLineupResponse(['good-station'])),
        'json.schedulesdirect.org/20141201/lineups/USA-BROKEN' => Http::response([
            'map' => [['stationID' => 'broken-station']],
            'stations' => [],
        ]),
    ]);

    expect(fn () => app(SchedulesDirectService::class)->syncEpgData($epg))
        ->toThrow(Exception::class, 'inconsistent map and stations');

    expect($epg->fresh()->sd_station_ids)->toBe(['old-station'])
        ->and(Storage::disk('local')->get($epg->file_path))->toBe('existing XMLTV data');
});

it('rejects an empty valid lineup when existing stations would be replaced', function () {
    $epg = ($this->makeEpg)(['USA-EMPTY'], ['sd_station_ids' => ['old-station']]);
    Storage::disk('local')->put($epg->file_path, 'existing XMLTV data');

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-EMPTY' => Http::response(sdLineupResponse([])),
    ]);

    expect(fn () => app(SchedulesDirectService::class)->syncEpgData($epg))
        ->toThrow(Exception::class, 'empty lineup');

    expect($epg->fresh()->sd_station_ids)->toBe(['old-station'])
        ->and(Storage::disk('local')->get($epg->file_path))->toBe('existing XMLTV data');
});

it('rejects an empty valid lineup when persisted import data exists despite empty station ids', function () {
    $epg = ($this->makeEpg)(['USA-EMPTY'], ['sd_station_ids' => []]);
    $epgChannel = EpgChannel::factory()->for($this->user)->for($epg)->create(['import_batch_no' => 'previous-batch']);
    $programme = EpgProgramme::factory()->for($epg)->create(['epg_channel_id' => $epgChannel->channel_id]);
    $channel = Channel::factory()->for($this->user)->create(['epg_channel_id' => $epgChannel->id]);
    Storage::disk('local')->put($epg->file_path, 'existing XMLTV data');

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-EMPTY' => Http::response(sdLineupResponse([])),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([]),
    ]);

    expect(fn () => app(SchedulesDirectService::class)->syncEpgData($epg))
        ->toThrow(Exception::class, 'empty lineup');

    expect($epg->fresh()->sd_station_ids)->toBe([])
        ->and(EpgChannel::find($epgChannel->id))->not->toBeNull()
        ->and(EpgProgramme::find($programme->id))->not->toBeNull()
        ->and(Channel::find($channel->id)?->epg_channel_id)->toBe($epgChannel->id)
        ->and(Storage::disk('local')->get($epg->file_path))->toBe('existing XMLTV data');
});

it('preserves persisted mappings when an empty lineup retry reaches its terminal attempt', function () {
    $epg = ($this->makeEpg)(['USA-EMPTY'], [
        'sd_station_ids' => [],
        'auto_resync_on_failure' => true,
        'auto_resync_retries' => 1,
    ]);
    $epgChannel = EpgChannel::factory()->for($this->user)->for($epg)->create(['import_batch_no' => 'previous-batch']);
    $programme = EpgProgramme::factory()->for($epg)->create(['epg_channel_id' => $epgChannel->channel_id]);
    $channel = Channel::factory()->for($this->user)->create(['epg_channel_id' => $epgChannel->id]);
    Storage::disk('local')->put($epg->file_path, 'existing XMLTV data');
    Bus::fake();

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-EMPTY' => Http::response(sdLineupResponse([])),
    ]);

    (new ProcessEpgImport($epg, force: true))->handle(app(SchedulesDirectService::class));

    $epg->refresh();
    expect($epg->errors)->toContain('empty lineup')
        ->and($epg->resync_attempt)->toBe(1)
        ->and(EpgChannel::find($epgChannel->id))->not->toBeNull()
        ->and(EpgProgramme::find($programme->id))->not->toBeNull()
        ->and(Channel::find($channel->id)?->epg_channel_id)->toBe($epgChannel->id)
        ->and(Storage::disk('local')->get($epg->file_path))->toBe('existing XMLTV data');
    $retry = null;
    Bus::assertDispatchedWithoutChain(ProcessEpgImport::class, function (ProcessEpgImport $job) use (&$retry, $epg) {
        if ($job->force === true && $job->epg->is($epg)) {
            $retry = $job;

            return true;
        }

        return false;
    });

    $retry->handle(app(SchedulesDirectService::class));

    $epg->refresh();
    expect($epg->resync_attempt)->toBe(1)
        ->and($epg->errors)->toContain('empty lineup')
        ->and(EpgChannel::find($epgChannel->id))->not->toBeNull()
        ->and(EpgProgramme::find($programme->id))->not->toBeNull()
        ->and(Channel::find($channel->id)?->epg_channel_id)->toBe($epgChannel->id)
        ->and(Storage::disk('local')->get($epg->file_path))->toBe('existing XMLTV data');
    Bus::assertDispatchedTimes(ProcessEpgImport::class, 1);
    Bus::assertNotDispatched(ProcessEpgImportComplete::class);
});

it('completes a nonempty SchedulesDirect import and replaces only the previous channel batch', function () {
    $epg = ($this->makeEpg)(['USA-NONEMPTY']);
    $previousChannel = EpgChannel::factory()->for($this->user)->for($epg)->create(['import_batch_no' => 'previous-batch']);

    Bus::fake();
    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-NONEMPTY' => Http::response(sdLineupResponse(['station-1'])),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([['stationID' => 'station-1', 'programs' => []]]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([]),
    ]);

    (new ProcessEpgImport($epg, force: true))->handle(app(SchedulesDirectService::class));

    $importChunk = null;
    Bus::assertDispatched(ProcessEpgImportChunk::class, function (ProcessEpgImportChunk $job) use (&$importChunk) {
        $importChunk = $job;

        return true;
    });
    $importChunk->handle();

    Bus::assertChained([
        ProcessEpgImportChunk::class,
        ProcessEpgImportComplete::class,
    ]);
    $batchNo = Job::sole()->batch_no;
    (new ProcessEpgImportComplete($this->user->id, $epg->id, $batchNo, now()))->handle();

    expect(EpgChannel::find($previousChannel->id))->toBeNull()
        ->and(EpgChannel::where('epg_id', $epg->id)->where('channel_id', 'station-1')->exists())->toBeTrue()
        ->and($epg->fresh()->processing)->toBeFalse();
});

it('completes a fresh empty SchedulesDirect import without touching an existing channel batch', function () {
    $epg = ($this->makeEpg)(['USA-FRESH-EMPTY']);

    Bus::fake();
    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-FRESH-EMPTY' => Http::response(sdLineupResponse([])),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([]),
    ]);

    (new ProcessEpgImport($epg, force: true))->handle(app(SchedulesDirectService::class));

    $completion = null;
    Bus::assertDispatched(ProcessEpgImportComplete::class, function (ProcessEpgImportComplete $job) use (&$completion) {
        $completion = $job;

        return true;
    });
    $completion->handle();

    expect(EpgChannel::where('epg_id', $epg->id)->count())->toBe(0)
        ->and($epg->fresh()->processing)->toBeFalse();
});

it('accepts matching lineup station ids in a different order', function () {
    $epg = ($this->makeEpg)(['USA-ORDERED']);
    $lineup = sdLineupResponse(['station-1', 'station-2']);
    $lineup['stations'] = array_reverse($lineup['stations']);

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-ORDERED' => Http::response($lineup),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([['stationID' => 'station-1', 'programs' => []], ['stationID' => 'station-2', 'programs' => []]]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([]),
    ]);

    app(SchedulesDirectService::class)->syncEpgData($epg);

    expect($epg->fresh()->sd_station_ids)->toBe(['station-1', 'station-2']);
});

it('accepts a code zero lineup response with matching stations', function () {
    $epg = ($this->makeEpg)(['USA-OK']);
    $lineup = sdLineupResponse(['station-1']);
    $lineup['code'] = 0;

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-OK' => Http::response($lineup),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([['stationID' => 'station-1', 'programs' => []]]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([]),
    ]);

    app(SchedulesDirectService::class)->syncEpgData($epg);

    expect($epg->fresh()->sd_station_ids)->toBe(['station-1']);
});

it('allows an empty valid lineup for a fresh EPG', function () {
    $epg = ($this->makeEpg)(['USA-EMPTY']);

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-EMPTY' => Http::response(sdLineupResponse([])),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([]),
    ]);

    app(SchedulesDirectService::class)->syncEpgData($epg);

    expect($epg->fresh()->sd_station_ids)->toBe([]);
});

it('adds a selected lineup to the account when it is not subscribed yet', function () {
    $epg = ($this->makeEpg)(['USA-NEW']);
    $subscribed = false;

    Http::fake(function (Request $request) use (&$subscribed) {
        $url = $request->url();

        if (str_ends_with($url, '/lineups/USA-NEW') && $request->method() === 'PUT') {
            $subscribed = true;

            return Http::response(['code' => 0, 'response' => 'OK']);
        }
        if (str_ends_with($url, '/lineups/USA-NEW')) {
            return $subscribed
                ? Http::response(sdLineupResponse(['10']))
                : Http::response(['code' => 2104, 'message' => 'Lineup not in account'], 400);
        }
        if (str_ends_with($url, '/schedules')) {
            return Http::response([['stationID' => '10', 'programs' => []]]);
        }

        return Http::response([]);
    });

    app(SchedulesDirectService::class)->syncEpgData($epg);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT' && str_ends_with($request->url(), '/lineups/USA-NEW'));
    expect($epg->fresh()->sd_station_ids)->toBe(['10']);
});

it('only removes lineups on delete that no other EPG on the account still uses', function () {
    $epg = ($this->makeEpg)(['USA-OWN', 'USA-SHARED']);
    ($this->makeEpg)(['USA-SHARED']);

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response(['code' => 0, 'response' => 'OK']),
    ]);

    app(SchedulesDirectService::class)->removeConfiguredLineups($epg);

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/lineups/USA-OWN'));
    Http::assertNotSent(fn (Request $request) => str_ends_with($request->url(), '/lineups/USA-SHARED'));
});

it('backfills the lineup list from the legacy single lineup column', function () {
    $migration = require database_path('migrations/2026_09_28_120000_add_sd_lineup_ids_to_epgs_table.php');
    $migration->down();

    $epgId = Epg::factory()->create(['user_id' => $this->user->id, 'sd_lineup_id' => 'USA-LEGACY'])->id;
    $unconfiguredId = Epg::factory()->create(['user_id' => $this->user->id, 'sd_lineup_id' => null])->id;

    $migration->up();

    expect(Epg::find($epgId)->sd_lineup_ids)->toBe(['USA-LEGACY'])
        ->and(Epg::find($unconfiguredId)->sd_lineup_ids)->toBeNull();
});

/**
 * Fake SD where lineups listed in $unaddable aren't on the account and can't be added (slots full),
 * lineups in $failing return a server error, and every other lineup returns one station named after it.
 */
function sdFakeLineups(array $unaddable = [], array $failing = []): void
{
    Http::fake(function (Request $request) use ($unaddable, $failing) {
        $url = $request->url();

        if (str_contains($url, '/lineups/')) {
            $lineupId = basename($url);
            if (in_array($lineupId, $failing, true)) {
                return Http::response(['message' => 'Internal server error'], 500);
            }
            if (in_array($lineupId, $unaddable, true)) {
                return $request->method() === 'PUT'
                    ? Http::response(['code' => 2102, 'message' => 'Maximum number of lineups reached'], 400)
                    : Http::response(['code' => 2104, 'message' => 'Lineup not in account'], 400);
            }

            return Http::response(sdLineupResponse([$lineupId]));
        }
        if (str_ends_with($url, '/schedules')) {
            return Http::response([['stationID' => 'USA-GOOD', 'programs' => []]]);
        }

        return Http::response([]);
    });
}

it('imports the remaining lineups and records an error when a lineup cannot be added', function () {
    $epg = ($this->makeEpg)(['USA-FULL', 'USA-GOOD']);
    sdFakeLineups(unaddable: ['USA-FULL']);

    app(SchedulesDirectService::class)->syncEpgData($epg);

    $epg->refresh();
    expect($epg->sd_station_ids)->toBe(['USA-GOOD'])
        ->and($epg->sd_last_sync)->not->toBeNull()
        ->and($epg->sd_errors)->toHaveCount(1)
        ->and($epg->sd_errors[0]['message'])->toStartWith('Lineup USA-FULL:');
});

it('fails the whole sync on a transient lineup error so existing channel mappings are kept', function () {
    $epg = ($this->makeEpg)(['USA-GOOD', 'USA-DOWN'], ['sd_station_ids' => ['USA-GOOD', 'USA-DOWN']]);
    sdFakeLineups(failing: ['USA-DOWN']);

    expect(fn () => app(SchedulesDirectService::class)->syncEpgData($epg))->toThrow(Exception::class);

    $epg->refresh();
    expect($epg->sd_last_sync)->toBeNull()
        ->and($epg->sd_station_ids)->toBe(['USA-GOOD', 'USA-DOWN']);
    Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
});

it('fails the sync and records every lineup when none can be added', function () {
    $epg = ($this->makeEpg)(['USA-FULL-1', 'USA-FULL-2']);
    sdFakeLineups(unaddable: ['USA-FULL-1', 'USA-FULL-2']);

    expect(fn () => app(SchedulesDirectService::class)->syncEpgData($epg))->toThrow(Exception::class);

    $epg->refresh();
    expect($epg->sd_last_sync)->toBeNull()
        ->and($epg->sd_errors[0]['message'])->toContain('Lineup USA-FULL-1:')->toContain('Lineup USA-FULL-2:');
});

it('restores the first selected lineup to the legacy column on rollback', function () {
    $migration = require database_path('migrations/2026_09_28_120000_add_sd_lineup_ids_to_epgs_table.php');
    $epgId = ($this->makeEpg)(['USA-FIRST', 'USA-SECOND'], ['sd_lineup_id' => 'USA-STALE'])->id;
    $clearedId = ($this->makeEpg)([], ['sd_lineup_id' => 'USA-STALE'])->id;

    $migration->down();

    expect(DB::table('epgs')->where('id', $epgId)->value('sd_lineup_id'))->toBe('USA-FIRST')
        ->and(DB::table('epgs')->where('id', $clearedId)->value('sd_lineup_id'))->toBeNull();

    $migration->up();
});
