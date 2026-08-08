<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake();
});

it('lets the owner stop a live channel belonging to their playlist', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $response = $this->postJson(
        "/api/tv/{$user->name}/{$playlist->uuid}/player-stream/stop",
        ['type' => 'live', 'stream_id' => $channel->id, 'client_id' => 'client-1']
    );

    $response->assertNoContent();
    Http::assertSentCount(1);
});

it('prevents the owner from stopping a channel belonging to another playlist', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->create();
    $foreignChannel = Channel::factory()->create([
        'playlist_id' => $otherPlaylist->id,
        'user_id' => $otherUser->id,
    ]);

    $response = $this->postJson(
        "/api/tv/{$user->name}/{$playlist->uuid}/player-stream/stop",
        ['type' => 'live', 'stream_id' => $foreignChannel->id, 'client_id' => 'client-1']
    );

    // Same 204 whether denied or actually stopped, but the proxy is never called.
    $response->assertNoContent();
    Http::assertNothingSent();
});

it('uses the episode id and checks playlist ownership for series type', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $episode = Episode::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $response = $this->postJson(
        "/api/tv/{$user->name}/{$playlist->uuid}/player-stream/stop",
        ['type' => 'series', 'episode_id' => $episode->id, 'client_id' => 'client-1']
    );

    $response->assertNoContent();
    Http::assertSentCount(1);
});

it('rejects invalid credentials', function () {
    $user = User::factory()->create();
    Playlist::factory()->for($user)->create();

    $response = $this->postJson(
        "/api/tv/{$user->name}/not-a-real-password/player-stream/stop",
        ['type' => 'live', 'stream_id' => 1, 'client_id' => 'client-1']
    );

    $response->assertStatus(401);
    Http::assertNothingSent();
});

it('rejects an invalid client id via validation', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $response = $this->postJson(
        "/api/tv/{$user->name}/{$playlist->uuid}/player-stream/stop",
        ['type' => 'live', 'stream_id' => $channel->id, 'client_id' => 'has spaces/slash']
    );

    $response->assertStatus(422);
    Http::assertNothingSent();
});
