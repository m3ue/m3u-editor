<?php

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Prevent any real network call to the m3u-proxy service; let each test
    // assert whether a stop request was (or wasn't) actually sent.
    Http::fake();
});

it('returns no content and does not stop stream for unauthenticated caller', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $response = $this->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => $channel->id,
        'type' => 'channel',
    ]);

    $response->assertNoContent();
    Http::assertNothingSent();
});

it('lets the authenticated owner stop their own channel stream', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => $channel->id,
        'type' => 'channel',
    ]);

    $response->assertNoContent();
    Http::assertSentCount(1);
});

it('prevents an authenticated user from stopping another users channel stream', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $playlist = Playlist::factory()->for($owner)->create();
    $channel = Channel::factory()->create([
        'playlist_id' => $playlist->id,
        'user_id' => $owner->id,
    ]);

    $response = $this->actingAs($otherUser)->postJson('/api/m3u-proxy/player-stream/stop', [
        'id' => $channel->id,
        'type' => 'channel',
    ]);

    // Same 204 response as the owner case (so the caller can't use the
    // response to probe ownership), but the proxy must never be called.
    $response->assertNoContent();
    Http::assertNothingSent();
});

it('returns unprocessable when type or id is missing', function () {
    // Structural validation (malformed request) is a distinct concern from the
    // ownership check above and doesn't need to hide anything, so it reports
    // 422 rather than folding into the silent 204 contract.
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson('/api/m3u-proxy/player-stream/stop', []);

    $response->assertStatus(422);
    Http::assertNothingSent();
});
