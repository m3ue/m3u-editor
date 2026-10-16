<?php

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\CustomPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\PlaylistService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->username = 'testuser_'.Str::random(5);
    $this->password = 'testpass';

    $playlistAuth = PlaylistAuth::create([
        'name' => 'Test Auth',
        'username' => $this->username,
        'password' => $this->password,
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);

    $this->playlist->playlistAuths()->attach($playlistAuth);
});

it('rewrites xtream timeshift URL from /live/ to /timeshift/', function () {
    $request = Request::create('/timeshift/user/pass/30/2024-12-01:15-30-00/123.ts');
    $request->merge([
        'timeshift_duration' => 30,
        'timeshift_date' => '2024-12-01:15-30-00',
    ]);

    $streamUrl = 'https://provider.domain/live/user/pass/464938.ts';

    $result = PlaylistService::generateTimeshiftUrl($request, $streamUrl, $this->playlist);

    expect($result)
        ->toContain('/timeshift/')
        ->not->toContain('/live/')
        ->toContain('/user/pass/')
        ->toContain('/464938.ts');
});

it('uses app timezone (not hardcoded UTC) as the source when converting xtream timeshift date', function () {
    // Simulate an installation with a non-UTC app timezone (e.g. Australia/Melbourne = UTC+10).
    // The date sent by the player is in local time. The provider server timezone is UTC.
    // Expected: the stamp shifts by the UTC+10 → UTC offset (−10 h), producing 11:00 UTC from 21:00 AEST.
    config(['app.timezone' => 'Australia/Melbourne']);
    app('config')->set('app.timezone', 'Australia/Melbourne');
    date_default_timezone_set('Australia/Melbourne');

    $this->playlist->update(['server_timezone' => 'UTC']);

    $request = Request::create('/timeshift/user/pass/30/2024-12-01:21-00/123.ts');
    $request->merge([
        'timeshift_duration' => 30,
        'timeshift_date' => '2024-12-01:21-00',
    ]);

    $streamUrl = 'https://provider.domain/live/user/pass/464938.ts';

    $result = PlaylistService::generateTimeshiftUrl($request, $streamUrl, $this->playlist);

    // 21:00 Melbourne (AEDT = UTC+11 in December) converted to UTC = 10:00. The stamp should be 10:00, not 21:00.
    expect($result)->toContain('2024-12-01:10-00');

    // Restore default timezone so this test doesn't pollute others.
    date_default_timezone_set('UTC');
});

it('converts unix timestamp date parameter and produces correct timeshift URL', function () {
    // 2024-12-01 15:30:00 UTC as a unix timestamp
    $unixTimestamp = Carbon::create(2024, 12, 1, 15, 30, 0, 'UTC')->timestamp;

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => 'https://provider.domain/live/user/pass/464938.ts',
        'catchup' => '1',
    ]);

    $response = $this->get(route('xtream.stream.timeshift.root', [
        'username' => $this->username,
        'password' => $this->password,
        'duration' => 30,
        'date' => (string) $unixTimestamp,
        'streamId' => $channel->id,
        'format' => 'ts',
    ]));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('/timeshift/');
});

it('rewrites TiviMate utc timeshift URL from /live/ to /timeshift/', function () {
    // m3u-proxy only treats a URL as catchup (Range/416 handling, no live failover) when it
    // has a /timeshift/ path, so utc requests must not use /streaming/timeshift.php
    $utc = 1733067000; // 2024-12-01 15:30:00 UTC
    $lutc = $utc + 1800; // 30 minutes later

    $request = Request::create('/live/user/pass/123.ts', 'GET', [
        'utc' => $utc,
        'lutc' => $lutc,
    ]);

    $streamUrl = 'https://provider.domain/live/user/pass/464938.ts';

    $result = PlaylistService::generateTimeshiftUrl($request, $streamUrl, $this->playlist);

    expect($result)->toBe('https://provider.domain/timeshift/user/pass/30/2024-12-01:15-30/464938.ts');
});

it('only treats utc or both Xtream timeshift parameters as a catchup request', function (array $query, bool $expected) {
    expect(PlaylistService::isTimeshiftRequest(Request::create('/live/user/pass/1.ts', 'GET', $query)))->toBe($expected);
})->with([
    'utc' => [['utc' => 1733067000], true],
    'utc and lutc' => [['utc' => 1733067000, 'lutc' => 1733068800], true],
    'both xtream parameters' => [['timeshift_duration' => 30, 'timeshift_date' => '2024-12-01:15-30-00'], true],
    'duration only' => [['timeshift_duration' => 30], false],
    'date only' => [['timeshift_date' => '2024-12-01:15-30-00'], false],
    'none' => [[], false],
]);

it('redirects timeshift request with correct /timeshift/ URL when proxy is disabled', function () {
    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => 'https://provider.domain/live/user/pass/464938.ts',
    ]);

    $response = $this->get(route('xtream.stream.timeshift.root', [
        'username' => $this->username,
        'password' => $this->password,
        'duration' => 30,
        'date' => '2024-12-01:15-30-00',
        'streamId' => $channel->id,
        'format' => 'ts',
    ]));

    $response->assertRedirect();
    $redirectUrl = $response->headers->get('Location');

    // The redirect URL must use /timeshift/, not /live/
    expect($redirectUrl)
        ->toContain('/timeshift/')
        ->not->toMatch('#/live/#');
});

it('uses failover channel URL when primary channel has no catchup support', function () {
    $primaryChannel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => 'https://provider.domain/live/user/pass/hd-stream.ts',
        'catchup' => null,
    ]);

    $failoverChannel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => 'https://provider.domain/live/user/pass/sd-stream.ts',
        'catchup' => '1',
    ]);

    ChannelFailover::create([
        'user_id' => $this->user->id,
        'channel_id' => $primaryChannel->id,
        'channel_failover_id' => $failoverChannel->id,
        'sort' => 1,
        'metadata' => '{}',
    ]);

    $response = $this->get(route('xtream.stream.timeshift.root', [
        'username' => $this->username,
        'password' => $this->password,
        'duration' => 30,
        'date' => '2024-12-01:15-30-00',
        'streamId' => $primaryChannel->id,
        'format' => 'ts',
    ]));

    $response->assertRedirect();
    $redirectUrl = $response->headers->get('Location');

    // Must redirect using the failover channel's URL, not the HD primary
    expect($redirectUrl)
        ->toContain('sd-stream')
        ->not->toContain('hd-stream');
});

it('uses the failover channel with catchup for utc catchup requests on the live route', function () {
    // The M3U output's catchup-source is the live URL plus ?utc=&lutc=, so the live route has to
    // pick the catchup failover the same way the /timeshift/ route does.
    $primaryChannel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => 'https://provider.domain/live/user/pass/hd-stream.ts',
        'catchup' => null,
    ]);

    $failoverChannel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => 'https://provider.domain/live/user/pass/sd-stream.ts',
        'catchup' => '1',
    ]);

    ChannelFailover::create([
        'user_id' => $this->user->id,
        'channel_id' => $primaryChannel->id,
        'channel_failover_id' => $failoverChannel->id,
        'sort' => 1,
        'metadata' => '{}',
    ]);

    $liveUrl = route('xtream.stream.live.root', [
        'username' => $this->username,
        'password' => $this->password,
        'streamId' => $primaryChannel->id,
        'format' => 'ts',
    ]);

    $response = $this->get($liveUrl.'?utc='.(time() - 1800).'&lutc='.time());

    $response->assertRedirect();
    expect($response->headers->get('Location'))
        ->toContain('/timeshift/user/pass/30/')
        ->toContain('/sd-stream.ts')
        ->not->toContain('hd-stream');

    // A plain live request still plays the primary
    expect($this->get($liveUrl)->headers->get('Location'))->toContain('hd-stream');
});

it('uses primary channel URL for timeshift when it has catchup support', function () {
    $primaryChannel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => 'https://provider.domain/live/user/pass/hd-stream.ts',
        'catchup' => '1',
    ]);

    $failoverChannel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => 'https://provider.domain/live/user/pass/sd-stream.ts',
        'catchup' => '1',
    ]);

    ChannelFailover::create([
        'user_id' => $this->user->id,
        'channel_id' => $primaryChannel->id,
        'channel_failover_id' => $failoverChannel->id,
        'sort' => 1,
        'metadata' => '{}',
    ]);

    $response = $this->get(route('xtream.stream.timeshift.root', [
        'username' => $this->username,
        'password' => $this->password,
        'duration' => 30,
        'date' => '2024-12-01:15-30-00',
        'streamId' => $primaryChannel->id,
        'format' => 'ts',
    ]));

    $response->assertRedirect();
    $redirectUrl = $response->headers->get('Location');

    // Primary has catchup, so must use the HD primary URL
    expect($redirectUrl)
        ->toContain('hd-stream')
        ->not->toContain('sd-stream');
});

it('falls back to the channel catchup_source template when the upstream URL is not Xtream-shaped (xtream-format request)', function () {
    // Regression test for issue #1317: plain M3U catch-up providers (e.g. EPGenius) are not
    // Xtream servers, so their channel URLs never match the `/live/user/pass/id.ext` shape the
    // rewrite regex expects. Previously this silently returned the untouched live URL — the
    // channel's own catchup-source URL template (captured on import) must be used instead.
    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => 'https://provider.example/stream/464938/playlist.m3u8',
        'catchup' => 'append',
        'catchup_source' => 'https://provider.example/archive/464938?utc=${start}&lutc=${end}&offset=${duration}',
    ]);

    $request = Request::create('/timeshift/user/pass/30/2024-12-01:15-30-00/464938.ts');
    $request->merge([
        'timeshift_duration' => 30,
        'timeshift_date' => '2024-12-01:15-30-00',
    ]);

    $result = PlaylistService::generateTimeshiftUrl($request, $channel->url, $this->playlist, $channel);

    expect($result)
        ->toContain('https://provider.example/archive/464938')
        ->not->toContain('${start}')
        // ${duration} is seconds (Kodi IPTV Simple), so a 30 minute window is 1800
        ->toContain('offset=1800');
});

it('falls back to the channel catchup_source template when the upstream URL is not Xtream-shaped (TiviMate utc request)', function () {
    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => 'https://provider.example/stream/464938/playlist.m3u8',
        'catchup' => 'append',
        'catchup_source' => 'https://provider.example/archive/464938?utc={start}&duration={duration}',
    ]);

    $utc = time() - 1800;
    $lutc = time();
    $request = Request::create('/live/user/pass/464938.ts', 'GET', [
        'utc' => $utc,
        'lutc' => $lutc,
    ]);

    $result = PlaylistService::generateTimeshiftUrl($request, $channel->url, $this->playlist, $channel);

    expect($result)
        ->toContain('https://provider.example/archive/464938')
        ->toContain('utc='.$utc)
        ->toContain('duration=1800');
});

it('still prefers the standard Xtream rewrite over catchup_source when the upstream URL matches the Xtream shape', function () {
    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => 'https://provider.domain/live/user/pass/464938.ts',
        'catchup' => '1',
        'catchup_source' => 'https://should-not-be-used.example/${start}',
    ]);

    $request = Request::create('/timeshift/user/pass/30/2024-12-01:15-30-00/464938.ts');
    $request->merge([
        'timeshift_duration' => 30,
        'timeshift_date' => '2024-12-01:15-30-00',
    ]);

    $result = PlaylistService::generateTimeshiftUrl($request, $channel->url, $this->playlist, $channel);

    expect($result)
        ->toContain('/timeshift/')
        ->not->toContain('should-not-be-used');
});

it('resolves the provider timezone from the channel own source playlist for Custom playlists', function () {
    // Custom/Merged/Alias playlists can be composed of channels from several different source
    // playlists, so they no longer carry their own server_timezone. The channel's originating
    // Playlist (Channel::playlist()) is the source of truth instead.
    $this->playlist->update(['server_timezone' => 'UTC']);

    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'enabled' => true,
        'url' => 'https://provider.domain/live/user/pass/464938.ts',
    ]);
    $customPlaylist->channels()->attach($channel);

    $request = Request::create('/timeshift/user/pass/30/2024-12-01:21-00-00/464938.ts');
    $request->merge([
        'timeshift_duration' => 30,
        'timeshift_date' => '2024-12-01:21-00-00',
    ]);

    config(['app.timezone' => 'Australia/Melbourne']);
    app('config')->set('app.timezone', 'Australia/Melbourne');
    date_default_timezone_set('Australia/Melbourne');

    $result = PlaylistService::generateTimeshiftUrl($request, $channel->url, $customPlaylist, $channel);

    // 21:00 Melbourne (AEDT = UTC+11 in December) converted to UTC = 10:00, using the source
    // playlist's UTC server_timezone rather than any value on the Custom playlist itself.
    expect($result)->toContain('2024-12-01:10-00');

    date_default_timezone_set('UTC');
});

it('preserves original URL when timeshift parameters are absent', function () {
    $request = Request::create('/live/user/pass/123.ts');

    $streamUrl = 'https://provider.domain/live/user/pass/464938.ts';

    // No timeshift parameters, so URL should not be modified
    // generateTimeshiftUrl checks for filled params internally
    $hasTimeshiftParams = $request->filled('timeshift_duration') || $request->filled('timeshift_date') || $request->filled('utc');

    expect($hasTimeshiftParams)->toBeFalse();
});
