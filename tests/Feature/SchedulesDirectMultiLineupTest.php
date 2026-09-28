<?php

use App\Enums\EpgSourceType;
use App\Models\Epg;
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

it('imports the remaining lineups and records an error when one lineup fails', function () {
    $epg = ($this->makeEpg)(['USA-BAD', 'USA-GOOD']);

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-BAD' => Http::response(['code' => 2107, 'message' => 'Lineup deleted'], 400),
        'json.schedulesdirect.org/20141201/lineups/USA-GOOD' => Http::response(sdLineupResponse(['7'])),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([['stationID' => '7', 'programs' => []]]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([]),
    ]);

    app(SchedulesDirectService::class)->syncEpgData($epg);

    $epg->refresh();
    expect($epg->sd_station_ids)->toBe(['7'])
        ->and($epg->sd_last_sync)->not->toBeNull()
        ->and($epg->sd_errors)->toHaveCount(1)
        ->and($epg->sd_errors[0]['message'])->toStartWith('Lineup USA-BAD:');
});

it('fails the sync when every selected lineup fails', function () {
    $epg = ($this->makeEpg)(['USA-BAD-1', 'USA-BAD-2']);

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response(['code' => 2107, 'message' => 'Lineup deleted'], 400),
    ]);

    expect(fn () => app(SchedulesDirectService::class)->syncEpgData($epg))->toThrow(Exception::class);
    expect($epg->fresh()->sd_last_sync)->toBeNull();
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
