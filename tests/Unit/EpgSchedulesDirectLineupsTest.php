<?php

use App\Models\Epg;
use App\Services\SchedulesDirectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
});

it('uses the legacy lineup when no multi-lineup selection has been saved', function () {
    $epg = new Epg([
        'sd_lineup_id' => 'USA-LEGACY',
    ]);

    expect($epg->configuredSchedulesDirectLineupIds())->toBe(['USA-LEGACY']);
});

it('uses ordered unique multi-lineup selections and synchronizes the legacy primary lineup', function () {
    $epg = new Epg([
        'sd_lineup_id' => 'USA-OLD',
        'sd_lineup_ids' => ['USA-TWO', 'USA-ONE', 'USA-TWO', '', null],
    ]);

    $epg->synchronizeSchedulesDirectLineupIds();

    expect($epg->configuredSchedulesDirectLineupIds())->toBe(['USA-TWO', 'USA-ONE'])
        ->and($epg->sd_lineup_id)->toBe('USA-TWO')
        ->and($epg->sd_lineup_ids)->toBe(['USA-TWO', 'USA-ONE']);
});

it('merges stations from every configured lineup without duplicates', function () {
    $epg = Epg::withoutEvents(fn () => Epg::factory()->create([
        'sd_token' => 'token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_lineup_ids' => ['USA-ONE', 'USA-TWO'],
        'sd_lineup_id' => 'USA-ONE',
        'sd_days_to_import' => 1,
    ]));

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-ONE' => Http::response([
            'map' => [['stationID' => 'ONE', 'channel' => '1']],
            'stations' => [['stationID' => 'ONE', 'name' => 'One']],
        ]),
        'json.schedulesdirect.org/20141201/lineups/USA-TWO' => Http::response([
            'map' => [['stationID' => 'TWO', 'channel' => '2'], ['stationID' => 'ONE', 'channel' => '1']],
            'stations' => [['stationID' => 'TWO', 'name' => 'Two'], ['stationID' => 'ONE', 'name' => 'One']],
        ]),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([
            ['stationID' => 'ONE', 'programs' => []],
            ['stationID' => 'TWO', 'programs' => []],
        ]),
    ]);

    Storage::fake('local');

    (new SchedulesDirectService)->syncEpgData($epg);

    $xml = Storage::disk('local')->get($epg->file_path);

    expect($epg->fresh()->sd_station_ids)->toBe(['ONE', 'TWO'])
        ->and(substr_count($xml, '<channel id="ONE">'))->toBe(1)
        ->and(substr_count($xml, '<channel id="TWO">'))->toBe(1);
});

it('persists only selected account lineups and keeps the first one as the legacy primary', function () {
    $epg = Epg::withoutEvents(fn () => Epg::factory()->create([
        'sd_token' => 'token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_lineup_id' => 'USA-OLD',
    ]));

    Http::fake([
        'json.schedulesdirect.org/20141201/status' => Http::response(['account' => ['maxLineups' => 2]]),
        'json.schedulesdirect.org/20141201/lineups' => Http::response(['lineups' => [
            ['lineup' => 'USA-ONE'],
            ['lineup' => 'USA-TWO'],
        ]]),
    ]);

    (new SchedulesDirectService)->saveEpgLineupSelection($epg, ['USA-TWO', 'USA-ONE', 'USA-TWO']);

    expect($epg->fresh()->sd_lineup_ids)->toBe(['USA-TWO', 'USA-ONE'])
        ->and($epg->fresh()->sd_lineup_id)->toBe('USA-TWO');
});

it('URL encodes each configured lineup request', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/USA-NY12345-X%2Fplus' => Http::response(['map' => [], 'stations' => []]),
    ]);

    (new SchedulesDirectService)->getLineup('token', 'USA-NY12345-X/plus');

    Http::assertSent(fn ($request): bool => $request->url() === 'https://json.schedulesdirect.org/20141201/lineups/USA-NY12345-X%2Fplus');
});

it('does not remove an account lineup that this EPG has selected', function () {
    $epg = Epg::withoutEvents(fn () => Epg::factory()->create([
        'sd_token' => 'token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_lineup_ids' => ['USA-ONE', 'USA-TWO'],
        'sd_lineup_id' => 'USA-ONE',
    ]));

    Http::fake();

    expect(fn () => (new SchedulesDirectService)->removeLineupFromEpg($epg, 'USA-TWO'))
        ->toThrow(Exception::class, 'Remove this lineup from the EPG selection before removing it from the SchedulesDirect account.');

    Http::assertNothingSent();
});

it('rejects more selected lineups than the account permits', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/status' => Http::response(['account' => ['maxLineups' => 1]]),
    ]);

    expect(fn () => (new SchedulesDirectService)->validateEpgLineupSelection('token', ['USA-ONE', 'USA-TWO']))
        ->toThrow(Exception::class, 'Select at most 1 SchedulesDirect lineups for this EPG.');

    Http::assertSentCount(1);
});

it('does not remove an account lineup selected by another EPG on the same account', function () {
    $epg = Epg::withoutEvents(fn () => Epg::factory()->create([
        'sd_token' => 'token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_username' => 'shared@example.com',
    ]));
    Epg::withoutEvents(fn () => Epg::factory()->create([
        'user_id' => $epg->user_id,
        'source_type' => 'schedules_direct',
        'sd_username' => 'shared@example.com',
        'sd_lineup_ids' => ['USA-ONE'],
        'sd_lineup_id' => 'USA-ONE',
    ]));

    Http::fake();

    expect(fn () => (new SchedulesDirectService)->removeLineupFromEpg($epg, 'USA-ONE'))
        ->toThrow(Exception::class, 'Remove this lineup from every EPG using this SchedulesDirect account before removing it from the account.');

    Http::assertNothingSent();
});
