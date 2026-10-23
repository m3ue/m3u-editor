<?php

/**
 * Tests for XtreamStreamController::handleLive() resolving a network playlist's
 * HDHR/Xtream stream URL to the correct Network, rather than reinterpreting the
 * route's numeric id (the projected Channel's own primary key) as a Network id.
 *
 * Covers issue #1684: a projected Channel and a Network can have independently
 * allocated, colliding primary keys once other ordinary channels already exist.
 */

use App\Models\Channel;
use App\Models\Network;
use App\Models\Playlist;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'testuser']);
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'is_network_playlist' => true,
    ]);
});

it('resolves a network playlist stream via the projected channel network_id, even when their primary keys collide', function () {
    $ordinaryChannel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
    ]);

    $network = Network::factory()->for($this->user)->create([
        'id' => $ordinaryChannel->id,
        'network_playlist_id' => $this->playlist->id,
        'enabled' => true,
        'broadcast_enabled' => true,
    ]);

    $projectedChannel = Channel::where('network_id', $network->id)->first();

    $otherNetwork = Network::factory()->for($this->user)->create([
        'id' => $projectedChannel->id,
        'network_playlist_id' => $this->playlist->id,
        'enabled' => true,
        'broadcast_enabled' => true,
    ]);

    expect($network->id)->toBe($ordinaryChannel->id)
        ->and($otherNetwork->id)->toBe($projectedChannel->id)
        ->and($projectedChannel->id)->not->toBe($network->id);

    // The route must not reinterpret the projected channel id as the other network.
    $response = $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$projectedChannel->id}.m3u8");

    $response->assertRedirect($network->stream_url);
});

it('returns 404 when the projected channel does not resolve to a network', function () {
    $response = $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/999999.m3u8");

    $response->assertNotFound();
});
