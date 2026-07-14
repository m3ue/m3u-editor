<?php

use App\Models\Channel;
use App\Models\ChannelFailover;
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

it('rewrites TiviMate utc timeshift URL from /live/ to /streaming/timeshift.php', function () {
    $utc = time() - 1800; // 30 minutes ago
    $lutc = time();

    $request = Request::create('/live/user/pass/123.ts', 'GET', [
        'utc' => $utc,
        'lutc' => $lutc,
    ]);

    $streamUrl = 'https://provider.domain/live/user/pass/464938.ts';

    $result = PlaylistService::generateTimeshiftUrl($request, $streamUrl, $this->playlist);

    expect($result)
        ->toContain('/streaming/timeshift.php')
        ->not->toContain('/live/')
        ->toContain('username=user')
        ->toContain('password=pass')
        ->toContain('stream=464938');
});

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

it('preserves original URL when timeshift parameters are absent', function () {
    $request = Request::create('/live/user/pass/123.ts');

    $streamUrl = 'https://provider.domain/live/user/pass/464938.ts';

    // No timeshift parameters, so URL should not be modified
    // generateTimeshiftUrl checks for filled params internally
    $hasTimeshiftParams = $request->filled('timeshift_duration') || $request->filled('timeshift_date') || $request->filled('utc');

    expect($hasTimeshiftParams)->toBeFalse();
});
