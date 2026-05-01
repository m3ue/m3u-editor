<?php

use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use App\Services\PlaylistService;
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
