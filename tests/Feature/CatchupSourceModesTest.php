<?php

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\PlaylistService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/**
 * Catchup URLs for plain M3U providers (issue #1627, #1317), built from the channel's
 * `catchup` mode and `catchup-source` the way Kodi IPTV Simple does.
 */
uses(RefreshDatabase::class);

// 2024-12-01 15:30:00 UTC (10:30 in New York) to 16:00:00 UTC
const CATCHUP_TEST_START = 1733067000;
const CATCHUP_TEST_NOW = 1733068800;

beforeEach(function () {
    Event::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'server_timezone' => 'America/New_York',
        'disable_catchup' => false,
    ]);

    $this->makeChannel = fn (array $attributes): Channel => Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        ...$attributes,
    ]);

    $this->tiviMateTimeshift = fn (Channel $channel): string => PlaylistService::generateTimeshiftUrl(
        Request::create('/live/user/pass/1.ts', 'GET', ['utc' => CATCHUP_TEST_START, 'lutc' => CATCHUP_TEST_NOW]),
        $channel->url,
        $this->playlist,
        $channel
    );
});

it('appends a relative catchup-source to the stream URL in append mode', function () {
    $channel = ($this->makeChannel)([
        'url' => 'https://epg.example/ch/464938.m3u8',
        'catchup' => 'append',
        'catchup_source' => '?utc={utc}&lutc={lutc}',
    ]);

    expect(($this->tiviMateTimeshift)($channel))
        ->toBe('https://epg.example/ch/464938.m3u8?utc='.CATCHUP_TEST_START.'&lutc='.CATCHUP_TEST_NOW);
});

it('appends the SIPTV query in shift mode without a catchup-source', function (string $url, string $expected) {
    $channel = ($this->makeChannel)(['url' => $url, 'catchup' => 'shift', 'catchup_source' => null]);

    expect(($this->tiviMateTimeshift)($channel))->toBe($expected);
})->with([
    'no query' => ['https://epg.example/ch/1.ts', 'https://epg.example/ch/1.ts?utc='.CATCHUP_TEST_START.'&lutc='.CATCHUP_TEST_NOW],
    'existing query' => ['https://epg.example/ch/1.ts?token=abc', 'https://epg.example/ch/1.ts?token=abc&utc='.CATCHUP_TEST_START.'&lutc='.CATCHUP_TEST_NOW],
]);

it('returns the stream URL unchanged in default mode without a catchup-source', function () {
    $channel = ($this->makeChannel)([
        'url' => 'https://epg.example/ch/1.ts',
        'catchup' => 'default',
        'catchup_source' => null,
    ]);

    expect(($this->tiviMateTimeshift)($channel))->toBe('https://epg.example/ch/1.ts');
});

it('fills date specifiers in the provider timezone', function () {
    $channel = ($this->makeChannel)([
        'url' => 'https://epg.example/ch/1.ts',
        'catchup' => 'default',
        'catchup_source' => 'https://epg.example/archive/{Y}{m}{d}/{H}{M}{S}.ts?from={utc:Y-m-d:H-M}&to=${end:H-M}',
    ]);

    expect(($this->tiviMateTimeshift)($channel))
        ->toBe('https://epg.example/archive/20241201/103000.ts?from=2024-12-01:10-30&to=11-00');
});

it('fills timestamp, duration and offset specifiers with Kodi units', function () {
    $channel = ($this->makeChannel)([
        'url' => 'https://epg.example/ch/1.ts',
        'catchup' => 'append',
        'catchup_source' => '?start=${start}&end={utcend}&now=${timestamp}&l={lutc}&d={duration}&dm={duration:60}&o=${offset}&om={offset:60}',
    ]);

    expect(($this->tiviMateTimeshift)($channel))->toBe(
        'https://epg.example/ch/1.ts?start='.CATCHUP_TEST_START.'&end='.CATCHUP_TEST_NOW.'&now='.CATCHUP_TEST_NOW
        .'&l='.CATCHUP_TEST_NOW.'&d=1800&dm=30&o=1800&om=30'
    );
});

it('uses the programme window and current time for Xtream API timeshift requests', function () {
    // timeshift_date is read in the app timezone
    config(['app.timezone' => 'UTC']);
    $this->travelTo(Carbon::createFromTimestampUTC(CATCHUP_TEST_START + 5400));

    $channel = ($this->makeChannel)([
        'url' => 'https://epg.example/ch/1.ts',
        'catchup' => 'append',
        'catchup_source' => '?utc={utc}&lutc={lutc}&d={duration}&o={offset:60}',
    ]);

    $request = Request::create('/timeshift/user/pass/60/2024-12-01:15-30-00/1.ts');
    $request->merge(['timeshift_duration' => 60, 'timeshift_date' => '2024-12-01:15-30-00']);

    expect(PlaylistService::generateTimeshiftUrl($request, $channel->url, $this->playlist, $channel))->toBe(
        'https://epg.example/ch/1.ts?utc='.CATCHUP_TEST_START.'&lutc='.(CATCHUP_TEST_START + 5400).'&d=3600&o=90'
    );
});

it('builds Flussonic catchup URLs from the stream URL', function (string $mode, string $url, string $expected) {
    $channel = ($this->makeChannel)(['url' => $url, 'catchup' => $mode, 'catchup_source' => null]);

    expect(($this->tiviMateTimeshift)($channel))->toBe($expected);
})->with([
    'mpegts' => ['flussonic', 'http://fs.example/151/mpegts?token=t', 'http://fs.example/151/timeshift_abs-'.CATCHUP_TEST_START.'.ts?token=t'],
    'index hls' => ['flussonic', 'http://fs.example:8888/325/index.m3u8?token=t', 'http://fs.example:8888/325/timeshift_rel-1800.m3u8?token=t'],
    'named hls' => ['flussonic-hls', 'http://fs.example:8888/325/mono.m3u8', 'http://fs.example:8888/325/mono-timeshift_rel-1800.m3u8'],
    'generic ts' => ['fs', 'http://fs.example/325/live?token=t', 'http://fs.example/325/timeshift_abs-'.CATCHUP_TEST_START.'.ts?token=t'],
]);

it('builds an Xtream Codes catchup URL in xc mode', function () {
    $channel = ($this->makeChannel)([
        'url' => 'http://xc.example:8080/user@xc/pass/1477',
        'catchup' => 'xc',
        'catchup_source' => null,
    ]);

    expect(($this->tiviMateTimeshift)($channel))
        ->toBe('http://xc.example:8080/timeshift/user@xc/pass/30/2024-12-01:10-30/1477.ts');
});

it('outputs the internal SIPTV catchup-source in default mode for client-built modes', function () {
    config(['app.disable_m3u_xtream_format' => false]);

    $channel = ($this->makeChannel)([
        'url' => 'https://epg.example/ch/1.ts',
        'catchup' => 'append',
        'catchup_source' => '?utc={utc}&lutc={lutc}',
    ]);

    $content = $this->get(route('playlist.generate', ['uuid' => $this->playlist->uuid]))
        ->assertSuccessful()
        ->streamedContent();

    expect($content)
        ->toContain('catchup="default"')
        ->toMatch('~catchup-source="[^"]+/live/[^/"]+/[^/"]+/'.$channel->id.'\.ts\?utc=\{utc\}&lutc=\{lutc\}"~')
        ->not->toContain('catchup="append"');
});

it('keeps the provider catchup mode when passing its catchup-source through', function () {
    config(['app.disable_m3u_xtream_format' => true]);

    ($this->makeChannel)([
        'url' => 'https://epg.example/ch/1.ts',
        'catchup' => 'append',
        'catchup_source' => '?utc={utc}&lutc={lutc}',
    ]);

    $content = $this->get(route('playlist.generate', ['uuid' => $this->playlist->uuid]))
        ->assertSuccessful()
        ->streamedContent();

    expect($content)
        ->toContain('catchup="append"')
        ->toContain('catchup-source="?utc={utc}&lutc={lutc}"');
});

it('outputs the archive window in days', function (int $shiftHours, int $days) {
    ($this->makeChannel)([
        'url' => 'https://epg.example/ch/1.ts',
        'catchup' => 'default',
        'shift' => $shiftHours,
    ]);

    $content = $this->get(route('playlist.generate', ['uuid' => $this->playlist->uuid]))
        ->assertSuccessful()
        ->streamedContent();

    expect($content)->toContain("catchup-days=\"{$days}\" timeshift=\"{$days}\"");
})->with([
    'whole days' => [72, 3],
    'part day rounds up' => [36, 2],
]);
