<?php

/**
 * The M3U playlist generator (PlaylistGenerateController) must mirror the Xtream API's
 * proxy-awareness: a channel can force the proxy path via its own `enable_proxy` toggle
 * even when the playlist-level toggle is off, and when the proxy is in play the output
 * URL's extension should reflect the playlist's configured proxy output format (mapped
 * hls -> m3u8), not just the raw provider extension.
 */

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

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
