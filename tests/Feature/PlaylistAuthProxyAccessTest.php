<?php

/**
 * Tests for capability-aware proxy access:
 *
 * - player_api advertises the 'proxy' feature and the transcoding profiles the
 *   authenticated user may apply (owner/alias = all owner profiles, PlaylistAuth =
 *   gated by proxy_enabled + proxy_profile_access), plus whether the proxy is
 *   forced at the playlist level.
 * - A client-requested ?profile=<id>|none on Xtream stream URLs is validated
 *   server-side and applied as the stream profile (replacing the playlist-level
 *   default; channel-level profiles still win).
 */

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\Season;
use App\Models\Series;
use App\Models\StreamProfile;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create([
        'name' => 'owner',
        'permissions' => ['use_proxy'],
    ]);
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => false,
        'xtream' => false,
    ]);

    $this->makeAuth = function (array $attributes = []): PlaylistAuth {
        $auth = PlaylistAuth::create(array_merge([
            'name' => 'TV Auth',
            'username' => 'tvuser',
            'password' => 'tvpass',
            'enabled' => true,
            'user_id' => $this->user->id,
        ], $attributes));
        $auth->assignTo($this->playlist);

        return $auth->fresh();
    };

    $this->playerApi = function (string $username, string $password) {
        return $this->get('/player_api.php?'.http_build_query([
            'username' => $username,
            'password' => $password,
        ]));
    };

    $this->makeEpisode = function (): Episode {
        $series = Series::factory()->for($this->user)->for($this->playlist)->create(['enabled' => true]);
        $season = Season::factory()->for($this->user)->for($this->playlist)->for($series)->create();

        return Episode::factory()->for($this->user)->for($this->playlist)->for($series)->for($season)->create([
            'container_extension' => 'mp4',
        ]);
    };

    $this->mockChannelUrl = function (&$capturedProfile) {
        $mock = Mockery::mock(M3uProxyService::class);
        $mock->shouldReceive('getChannelUrl')
            ->once()
            ->withArgs(function ($playlist, $ch, $request, $profile) use (&$capturedProfile) {
                $capturedProfile = $profile;

                return true;
            })
            ->andReturn('http://proxy.test/redirected');
        app()->instance(M3uProxyService::class, $mock);
    };
});

// ── player_api feature advertisement ─────────────────────────────────────────

test('owner auth advertises proxy feature with all owner profiles', function () {
    $profileA = StreamProfile::factory()->for($this->user)->create(['name' => 'A 1080p']);
    $profileB = StreamProfile::factory()->for($this->user)->create(['name' => 'B 720p']);

    $response = ($this->playerApi)($this->user->name, $this->playlist->uuid);

    $response->assertOk();
    $payload = $response->json('m3u_editor');

    expect($payload['features'])->toContain('proxy')
        ->and($payload['proxy']['forced'])->toBeFalse()
        ->and(collect($payload['proxy']['profiles'])->pluck('id')->all())
        ->toBe([$profileA->id, $profileB->id]);
});

test('proxy profile payload never exposes ffmpeg args or cookies path', function () {
    StreamProfile::factory()->for($this->user)->create();

    $response = ($this->playerApi)($this->user->name, $this->playlist->uuid);

    $profile = $response->json('m3u_editor.proxy.profiles.0');

    expect(array_keys($profile))->toBe(['id', 'name', 'description', 'format'])
        ->and($response->getContent())->not->toContain('libx264');
});

test('owner without use_proxy permission does not advertise proxy', function () {
    $this->user->update(['permissions' => []]);

    $response = ($this->playerApi)($this->user->name, $this->playlist->uuid);

    $payload = $response->json('m3u_editor');

    expect($payload['features'])->not->toContain('proxy')
        ->and($payload)->not->toHaveKey('proxy');
});

test('proxy is not advertised when the integration is globally disabled', function () {
    config(['proxy.proxy_integration_enabled' => false]);

    $response = ($this->playerApi)($this->user->name, $this->playlist->uuid);

    expect($response->json('m3u_editor.features'))->not->toContain('proxy');
});

test('playlist auth without proxy_enabled does not advertise proxy', function () {
    ($this->makeAuth)(['proxy_enabled' => false]);

    $response = ($this->playerApi)('tvuser', 'tvpass');

    $payload = $response->json('m3u_editor');

    expect($payload['features'])->not->toContain('proxy')
        ->and($payload)->not->toHaveKey('proxy');
});

test('playlist auth with proxy_enabled and all-profile access advertises every owner profile', function () {
    $profileA = StreamProfile::factory()->for($this->user)->create(['name' => 'A']);
    $profileB = StreamProfile::factory()->for($this->user)->create(['name' => 'B']);
    ($this->makeAuth)(['proxy_enabled' => true, 'proxy_profile_access' => 'all']);

    $response = ($this->playerApi)('tvuser', 'tvpass');

    $payload = $response->json('m3u_editor');

    expect($payload['features'])->toContain('proxy')
        ->and(collect($payload['proxy']['profiles'])->pluck('id')->all())
        ->toBe([$profileA->id, $profileB->id]);
});

test('playlist auth with selected profile access advertises only the allowed profiles', function () {
    StreamProfile::factory()->for($this->user)->create(['name' => 'A']);
    $allowed = StreamProfile::factory()->for($this->user)->create(['name' => 'B']);
    ($this->makeAuth)([
        'proxy_enabled' => true,
        'proxy_profile_access' => 'selected',
        'proxy_stream_profile_ids' => [$allowed->id],
    ]);

    $response = ($this->playerApi)('tvuser', 'tvpass');

    expect(collect($response->json('m3u_editor.proxy.profiles'))->pluck('id')->all())
        ->toBe([$allowed->id]);
});

test('playlist auth with no profile access advertises proxy with an empty profile list', function () {
    StreamProfile::factory()->for($this->user)->create();
    ($this->makeAuth)(['proxy_enabled' => true, 'proxy_profile_access' => 'none']);

    $response = ($this->playerApi)('tvuser', 'tvpass');

    $payload = $response->json('m3u_editor');

    expect($payload['features'])->toContain('proxy')
        ->and($payload['proxy']['profiles'])->toBe([]);
});

test('proxy is advertised as forced when the playlist has enable_proxy set', function () {
    $this->playlist->update(['enable_proxy' => true]);
    ($this->makeAuth)(['proxy_enabled' => true]);

    $response = ($this->playerApi)('tvuser', 'tvpass');

    expect($response->json('m3u_editor.proxy.forced'))->toBeTrue();
});

// ── Client-requested profile on Xtream stream URLs ───────────────────────────

test('owner request with ?proxy=true&profile applies the requested profile', function () {
    $profile = StreamProfile::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $capturedProfile = null;
    ($this->mockChannelUrl)($capturedProfile);

    $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.ts?proxy=true&profile={$profile->id}")
        ->assertRedirect();

    expect($capturedProfile?->id)->toBe($profile->id);
});

test('profile=none suppresses the playlist-level default profile', function () {
    $playlistProfile = StreamProfile::factory()->for($this->user)->create();
    $this->playlist->update(['enable_proxy' => true, 'stream_profile_id' => $playlistProfile->id]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $capturedProfile = 'unset';
    ($this->mockChannelUrl)($capturedProfile);

    $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.ts?profile=none")
        ->assertRedirect();

    expect($capturedProfile)->toBeNull();
});

test('a channel-level profile still wins over the client-requested profile', function () {
    $channelProfile = StreamProfile::factory()->for($this->user)->create();
    $clientProfile = StreamProfile::factory()->for($this->user)->create();
    $this->playlist->update(['enable_proxy' => true]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'stream_profile_id' => $channelProfile->id,
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $capturedProfile = null;
    ($this->mockChannelUrl)($capturedProfile);

    $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.ts?profile={$clientProfile->id}")
        ->assertRedirect();

    expect($capturedProfile?->id)->toBe($channelProfile->id);
});

test('playlist auth may apply a profile its access list allows', function () {
    $profile = StreamProfile::factory()->for($this->user)->create();
    ($this->makeAuth)([
        'proxy_enabled' => true,
        'proxy_profile_access' => 'selected',
        'proxy_stream_profile_ids' => [$profile->id],
    ]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $capturedProfile = null;
    ($this->mockChannelUrl)($capturedProfile);

    $this->get("/live/tvuser/tvpass/{$channel->id}.ts?proxy=true&profile={$profile->id}")
        ->assertRedirect();

    expect($capturedProfile?->id)->toBe($profile->id);
});

test('playlist auth cannot apply a profile outside its access list', function () {
    $allowed = StreamProfile::factory()->for($this->user)->create();
    $denied = StreamProfile::factory()->for($this->user)->create();
    ($this->makeAuth)([
        'proxy_enabled' => true,
        'proxy_profile_access' => 'selected',
        'proxy_stream_profile_ids' => [$allowed->id],
    ]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $capturedProfile = 'unset';
    ($this->mockChannelUrl)($capturedProfile);

    // The unauthorized selection is ignored; the stream still plays with defaults.
    $this->get("/live/tvuser/tvpass/{$channel->id}.ts?proxy=true&profile={$denied->id}")
        ->assertRedirect();

    expect($capturedProfile)->toBeNull();
});

test('playlist auth without proxy_enabled cannot apply any profile', function () {
    $profile = StreamProfile::factory()->for($this->user)->create();
    ($this->makeAuth)(['proxy_enabled' => false]);

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $capturedProfile = 'unset';
    ($this->mockChannelUrl)($capturedProfile);

    // ?proxy=true itself keeps working (back-compat); only the profile is ignored.
    $this->get("/live/tvuser/tvpass/{$channel->id}.ts?proxy=true&profile={$profile->id}")
        ->assertRedirect();

    expect($capturedProfile)->toBeNull();
});

test('a profile belonging to another user is ignored', function () {
    $otherUser = User::factory()->create(['permissions' => ['use_proxy']]);
    $foreignProfile = StreamProfile::factory()->for($otherUser)->create();

    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
    ]);

    $capturedProfile = 'unset';
    ($this->mockChannelUrl)($capturedProfile);

    $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$channel->id}.ts?proxy=true&profile={$foreignProfile->id}")
        ->assertRedirect();

    expect($capturedProfile)->toBeNull();
});

test('timeshift request with ?proxy=true&profile applies the requested profile', function () {
    $profile = StreamProfile::factory()->for($this->user)->create();
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'url' => 'http://provider.test/stream/live.ts',
        'is_vod' => false,
        'enabled' => true,
        'catchup' => 'default',
    ]);

    $capturedProfile = null;
    ($this->mockChannelUrl)($capturedProfile);

    $this->get("/timeshift/{$this->user->name}/{$this->playlist->uuid}/60/2026-01-01:00-00-00/{$channel->id}.ts?proxy=true&profile={$profile->id}")
        ->assertRedirect();

    expect($capturedProfile?->id)->toBe($profile->id);
});

test('episode request with ?proxy=true&profile applies the requested profile', function () {
    $profile = StreamProfile::factory()->for($this->user)->create();
    $episode = ($this->makeEpisode)();

    $capturedProfile = null;
    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getEpisodeUrl')
        ->once()
        ->withArgs(function ($playlist, $ep, $episodeProfile) use (&$capturedProfile) {
            $capturedProfile = $episodeProfile;

            return true;
        })
        ->andReturn('http://proxy.test/redirected');
    app()->instance(M3uProxyService::class, $mock);

    $this->get("/series/{$this->user->name}/{$this->playlist->uuid}/{$episode->id}.mp4?proxy=true&profile={$profile->id}")
        ->assertRedirect();

    expect($capturedProfile?->id)->toBe($profile->id);
});

test('episode request with profile=none suppresses the playlist vod profile', function () {
    $vodProfile = StreamProfile::factory()->for($this->user)->create();
    $this->playlist->update(['enable_proxy' => true, 'vod_stream_profile_id' => $vodProfile->id]);

    $episode = ($this->makeEpisode)();

    $capturedProfile = 'unset';
    $mock = Mockery::mock(M3uProxyService::class);
    $mock->shouldReceive('getEpisodeUrl')
        ->once()
        ->withArgs(function ($playlist, $ep, $episodeProfile) use (&$capturedProfile) {
            $capturedProfile = $episodeProfile;

            return true;
        })
        ->andReturn('http://proxy.test/redirected');
    app()->instance(M3uProxyService::class, $mock);

    $this->get("/series/{$this->user->name}/{$this->playlist->uuid}/{$episode->id}.mp4?profile=none")
        ->assertRedirect();

    expect($capturedProfile)->toBeNull();
});

// ── PlaylistAuth::allowsProxyStreamProfile ───────────────────────────────────

test('allowsProxyStreamProfile honors access modes', function () {
    $auth = ($this->makeAuth)([
        'proxy_enabled' => true,
        'proxy_profile_access' => 'selected',
        'proxy_stream_profile_ids' => [7, 9],
    ]);

    expect($auth->allowsProxyStreamProfile(7))->toBeTrue()
        ->and($auth->allowsProxyStreamProfile(8))->toBeFalse();

    $auth->update(['proxy_profile_access' => 'all']);
    expect($auth->fresh()->allowsProxyStreamProfile(8))->toBeTrue();

    $auth->update(['proxy_profile_access' => 'none']);
    expect($auth->fresh()->allowsProxyStreamProfile(7))->toBeFalse();

    $auth->update(['proxy_profile_access' => 'all', 'proxy_enabled' => false]);
    expect($auth->fresh()->allowsProxyStreamProfile(7))->toBeFalse();
});
