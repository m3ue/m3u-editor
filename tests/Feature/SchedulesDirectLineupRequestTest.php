<?php

use App\Models\Epg;
use App\Services\SchedulesDirectService;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
});

it('adds a lineup with PUT to the encoded lineup resource and an empty body', function () {
    Http::fake([
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response(['code' => 0, 'message' => 'OK']),
    ]);

    $lineupId = 'USA-NY12345-X/plus';
    $result = (new SchedulesDirectService)->addLineup('token', $lineupId);

    expect($result)->toBe(['code' => 0, 'message' => 'OK']);
    Http::assertSent(function ($request) use ($lineupId) {
        return $request->method() === 'PUT'
            && $request->url() === 'https://json.schedulesdirect.org/20141201/lineups/'.rawurlencode($lineupId)
            && $request->body() === '';
    });
});

it('uses the same PUT lineup contract through the EPG add action service path', function () {
    $epg = Epg::withoutEvents(fn () => Epg::factory()->create([
        'sd_token' => 'token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_lineup_id' => 'USA-NY12345-X',
    ]));

    Http::fake([
        'json.schedulesdirect.org/20141201/status' => Http::response(['account' => ['maxLineups' => 4]]),
        'json.schedulesdirect.org/20141201/lineups' => Http::sequence()
            ->push(['lineups' => []])
            ->push(['lineups' => [['lineup' => 'USA-NY12345-X/plus']]]),
        'json.schedulesdirect.org/20141201/lineups/*' => Http::response(['code' => 0]),
    ]);

    (new SchedulesDirectService)->addLineupToEpg($epg, 'USA-NY12345-X/plus');

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'https://json.schedulesdirect.org/20141201/lineups/USA-NY12345-X%2Fplus'
        && $request->body() === '');
});
