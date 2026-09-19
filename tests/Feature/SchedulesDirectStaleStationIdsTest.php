<?php

use App\Models\Epg;
use App\Models\User;
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

it('refreshes sd_station_ids from the current lineup on every sync instead of caching stale ids', function () {
    $user = User::factory()->create();

    // Simulate an EPG that already has a cached station list containing a
    // stationID that Schedules Direct has since removed from the lineup.
    $epg = Epg::factory()->create([
        'user_id' => $user->id,
        'sd_username' => 'test@example.com',
        'sd_password' => 'password',
        'sd_token' => 'valid-token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_lineup_id' => 'USA-NY12345-X',
        'sd_station_ids' => ['12345', '99999'], // 99999 is stale/no longer in the lineup
        'sd_days_to_import' => 1,
    ]);

    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response([
            'map' => [
                ['stationID' => '12345', 'channel' => '1.1'],
            ],
            'stations' => [
                ['stationID' => '12345', 'name' => 'Test Channel 1', 'callsign' => 'TEST1'],
            ],
        ]),
        'json.schedulesdirect.org/20141201/schedules' => Http::response([
            [
                'stationID' => '12345',
                'programs' => [],
            ],
        ]),
        'json.schedulesdirect.org/20141201/programs' => Http::response([]),
    ]);

    Storage::fake('local');

    (new SchedulesDirectService)->syncEpgData($epg);

    expect($epg->fresh()->sd_station_ids)->toBe(['12345']);
});
