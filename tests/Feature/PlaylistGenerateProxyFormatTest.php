<?php

/**
 * The M3U playlist generator (PlaylistGenerateController) must mirror the Xtream API's
 * proxy-awareness: a channel can force the proxy path via its own `enable_proxy` toggle
 * even when the playlist-level toggle is off, and when the proxy is in play the output
 * URL's extension should reflect the playlist's configured proxy output format (mapped
 * hls -> m3u8), not just the raw provider extension.
 */

use App\Http\Controllers\PlaylistGenerateController;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create([
        'permissions' => ['use_proxy'],
    ]);
});

it('uses the channel-level proxy override and the playlist output format even when playlist-level proxy is off', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => false,
        'xtream' => true,
        'xtream_config' => ['output' => 'hls'],
    ]);
    $group = Group::factory()->for($playlist)->for($this->user)->create();

    $channel = Channel::factory()->for($this->user)->for($playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'enable_proxy' => true,
        'url' => 'http://provider.example.com/live/olduser/oldpass/1234',
    ]);

    $response = $this->get("/{$playlist->uuid}/playlist.m3u");

    $response->assertStatus(200);
    $username = urlencode($this->user->name);
    $password = urlencode($playlist->uuid);
    expect($response->streamedContent())
        ->toContain("/live/{$username}/{$password}/{$channel->id}.m3u8");
});

it('does not force the proxy output format when neither the channel nor the playlist has proxy enabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => false,
        'xtream' => true,
        'xtream_config' => ['output' => 'hls'],
    ]);
    $group = Group::factory()->for($playlist)->for($this->user)->create();

    $channel = Channel::factory()->for($this->user)->for($playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'enable_proxy' => false,
        'url' => 'http://provider.example.com/live/olduser/oldpass/1234.ts',
    ]);

    $response = $this->get("/{$playlist->uuid}/playlist.m3u");

    $response->assertStatus(200);
    $username = urlencode($this->user->name);
    $password = urlencode($playlist->uuid);
    expect($response->streamedContent())
        ->toContain("/live/{$username}/{$password}/{$channel->id}.ts");
});

it('HDHR lineup uses the channel-level proxy override and the playlist output format even when playlist-level proxy is off', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => false,
        'xtream' => true,
        'xtream_config' => ['output' => 'hls'],
    ]);
    $group = Group::factory()->for($playlist)->for($this->user)->create();

    $channel = Channel::factory()->for($this->user)->for($playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'enable_proxy' => true,
        'url' => 'http://provider.example.com/live/olduser/oldpass/1234',
    ]);

    $response = $this->get("/{$playlist->uuid}/hdhr/lineup.json");

    $response->assertStatus(200);
    $lineup = json_decode($response->streamedContent(), true);

    expect($lineup)->toHaveCount(1)
        ->and($lineup[0]['URL'])->toContain("/live/{$this->user->name}/{$playlist->uuid}/{$channel->id}.m3u8");
});

it('HDHR lineup does not force the proxy output format when neither the channel nor the playlist has proxy enabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => false,
        'xtream' => true,
        'xtream_config' => ['output' => 'hls'],
    ]);
    $group = Group::factory()->for($playlist)->for($this->user)->create();

    $channel = Channel::factory()->for($this->user)->for($playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'enable_proxy' => false,
        'url' => 'http://provider.example.com/live/olduser/oldpass/1234.ts',
    ]);

    $response = $this->get("/{$playlist->uuid}/hdhr/lineup.json");

    $response->assertStatus(200);
    $lineup = json_decode($response->streamedContent(), true);

    expect($lineup)->toHaveCount(1)
        ->and($lineup[0]['URL'])->toContain("/live/{$this->user->name}/{$playlist->uuid}/{$channel->id}.ts");
});

it('forces the proxy path when the source playlist pools provider profiles, even without any enable_proxy toggle', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => false,
        'profiles_enabled' => true,
        'xtream' => true,
        'xtream_config' => ['output' => 'hls'],
    ]);
    $group = Group::factory()->for($playlist)->for($this->user)->create();

    $channel = Channel::factory()->for($this->user)->for($playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'enable_proxy' => false,
        'url' => 'http://provider.example.com/live/olduser/oldpass/1234',
    ]);

    $response = $this->get("/{$playlist->uuid}/playlist.m3u");

    $response->assertStatus(200);
    $username = urlencode($this->user->name);
    $password = urlencode($playlist->uuid);
    expect($response->streamedContent())
        ->toContain("/live/{$username}/{$password}/{$channel->id}.m3u8");
});

it('uses the proxy output format for the catchup-source extension even when the internal Xtream format is disabled', function () {
    $playlist = Playlist::factory()->for($this->user)->create([
        'enable_proxy' => false,
        'disable_m3u_xtream_format' => true,
        'xtream' => true,
        'xtream_config' => ['output' => 'hls'],
    ]);
    $group = Group::factory()->for($playlist)->for($this->user)->create();

    $channel = Channel::factory()->for($this->user)->for($playlist)->for($group)->create([
        'enabled' => true,
        'is_vod' => false,
        'enable_proxy' => true,
        'catchup' => 'default',
        'url' => 'http://provider.example.com/live/olduser/oldpass/1234.ts',
    ]);

    $response = $this->get("/{$playlist->uuid}/playlist.m3u");

    $response->assertStatus(200);
    $username = urlencode($this->user->name);
    $password = urlencode($playlist->uuid);
    expect($response->streamedContent())
        ->toContain("/timeshift/{$username}/{$password}/{duration}/{start}/{$channel->id}.m3u8");
});

it('resolves the proxy output format for a PlaylistAlias via its wrapped playlist, not the alias own xtream_config list', function () {
    $wrapped = Playlist::factory()->for($this->user)->create([
        'xtream' => true,
        'xtream_config' => ['output' => 'hls'],
    ]);

    $alias = PlaylistAlias::create([
        'playlist_id' => $wrapped->id,
        'user_id' => $this->user->id,
        'name' => 'Test Alias',
        'uuid' => Str::uuid()->toString(),
        // The alias's own xtream_config is a normalized LIST of provider account
        // configs (url/username/password), never a keyed config with an 'output'
        // key - reading ['output'] directly off it must not be how the format
        // is resolved, or it silently falls back to 'ts'.
        'xtream_config' => [[
            'url' => 'http://provider.example.com:8080',
            'username' => 'aliasuser',
            'password' => 'aliaspass',
        ]],
    ]);

    $method = new ReflectionMethod(PlaylistGenerateController::class, 'resolveProxyOutputFormat');
    $method->setAccessible(true);

    expect($method->invoke(null, null, $alias))->toBe('m3u8');
});
