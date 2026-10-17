<?php

use App\Enums\EpgSourceType;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\User;
use App\Services\SchedulesDirectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
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

it('sends the documented method, path, headers and JSON body on every request of a full sync', function () {
    $epg = ($this->makeEpg)(['USA-A'], ['sd_token' => null, 'sd_token_expires_at' => null]);

    Http::fake([
        'json.schedulesdirect.org/20141201/token' => Http::response(['code' => 0, 'token' => 'fresh-token', 'tokenExpires' => now()->addDay()->timestamp]),
        'json.schedulesdirect.org/20141201/lineups/USA-A' => Http::response(sdLineupResponse(['1', '2'])),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([
            ['stationID' => '1', 'programs' => [['programID' => 'EP0000000001', 'airDateTime' => now()->toIso8601ZuluString(), 'duration' => 1800]]],
            ['stationID' => '2', 'programs' => [['programID' => 'EP0000000002', 'airDateTime' => now()->toIso8601ZuluString(), 'duration' => 1800]]],
        ]),
        'json.schedulesdirect.org/20141201/programs' => fn (Request $request) => Http::response(array_map(
            fn (string $programId) => ['programID' => $programId, 'titles' => [['title120' => "Synthetic {$programId}"]]],
            $request->data(),
        )),
    ]);

    app(SchedulesDirectService::class)->syncEpgData($epg);

    $isJsonPost = fn (Request $request, string $path) => $request->method() === 'POST'
        && $request->url() === "https://json.schedulesdirect.org/20141201{$path}"
        && $request->isJson()
        && str_starts_with($request->header('User-Agent')[0] ?? '', 'm3u-editor/');

    // SD expects the SHA1 hex digest of the password, never the plain text
    Http::assertSent(fn (Request $request) => $isJsonPost($request, '/token')
        && $request->data() === ['username' => 'test@example.com', 'password' => sha1('password')]
        && ! $request->hasHeader('token'));

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://json.schedulesdirect.org/20141201/lineups/USA-A'
        && $request->header('token') === ['fresh-token']);

    Http::assertSent(fn (Request $request) => $isJsonPost($request, '/schedules')
        && $request->header('token') === ['fresh-token']
        && $request->data() === [
            ['stationID' => '1', 'date' => [now()->format('Y-m-d')]],
            ['stationID' => '2', 'date' => [now()->format('Y-m-d')]],
        ]);

    // Programs are requested as a bare JSON list of IDs, one request per schedule sub-chunk
    foreach (['EP0000000001', 'EP0000000002'] as $programId) {
        Http::assertSent(fn (Request $request) => $isJsonPost($request, '/programs')
            && $request->header('token') === ['fresh-token']
            && $request->data() === [$programId]);
    }
    Http::assertSentCount(5);

    expect(Storage::disk('local')->get($epg->file_path))
        ->toContain('<programme channel="1"')
        ->toContain('<programme channel="2"')
        ->toContain('Synthetic EP0000000001')
        ->toContain('Synthetic EP0000000002');
});

it('keeps the existing EPG when a lineup response is unusable', function (mixed $response) {
    $epg = ($this->makeEpg)(['USA-BAD'], ['sd_station_ids' => ['old']]);
    Storage::disk('local')->put($epg->file_path, 'existing XMLTV data');

    Http::fake(['json.schedulesdirect.org/20141201/lineups/USA-BAD' => $response]);

    expect(fn () => app(SchedulesDirectService::class)->syncEpgData($epg))->toThrow(Exception::class);

    expect($epg->fresh()->sd_station_ids)->toBe(['old'])
        ->and(Storage::disk('local')->get($epg->file_path))->toBe('existing XMLTV data');
})->with([
    'error code in HTTP 200' => fn () => Http::response(['code' => 3000, 'message' => 'Service offline']),
    'malformed JSON' => fn () => Http::response('{', 200),
    'missing stations' => fn () => Http::response(['map' => []]),
]);

it('refuses an empty lineup for an EPG that already has channels', function () {
    $epg = ($this->makeEpg)(['USA-EMPTY']);
    $epgChannel = EpgChannel::factory()->for($this->user)->for($epg)->create();

    Http::fake(['json.schedulesdirect.org/20141201/lineups/USA-EMPTY' => Http::response(sdLineupResponse([]))]);

    expect(fn () => app(SchedulesDirectService::class)->syncEpgData($epg))
        ->toThrow(Exception::class, 'keeping the existing EPG data');

    expect(EpgChannel::find($epgChannel->id))->not->toBeNull();
});

it('allows an empty lineup for an EPG with no channels yet', function () {
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

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://json.schedulesdirect.org/20141201/lineups/USA-NEW'
        && $request->header('token') === ['valid-token']);
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
