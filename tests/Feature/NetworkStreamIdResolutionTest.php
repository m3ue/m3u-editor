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
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->user = User::factory()->create(['name' => 'testuser']);
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'is_network_playlist' => true,
    ]);
});

it('resolves a network playlist stream via the projected channel network_id, even when their primary keys collide', function () {
    // channels and networks are independent auto-increment sequences. Predicting the
    // Channel's future auto-assigned id from MAX(id) and pre-setting the Network's id to
    // match doesn't hold up under Postgres (CI's real test DB): unlike sqlite's rowid,
    // a Postgres sequence is NOT transactional - every prior test's rolled-back Channel
    // inserts in this worker still permanently advanced channels_id_seq, so the next
    // auto-assigned channel id can run far ahead of MAX(id) with no warning. Let the
    // Network and its projected Channel land wherever they naturally do, then force them
    // onto the same id afterward - nothing else references either row yet at this point.
    $network = Network::factory()->for($this->user)->create([
        'network_playlist_id' => $this->playlist->id,
        'enabled' => true,
        'broadcast_enabled' => true,
    ]);

    $projectedChannel = Channel::where('network_id', $network->id)->latest('id')->first();

    if ($projectedChannel->id !== $network->id) {
        $collisionId = (int) max(
            DB::table('channels')->max('id') ?? 0,
            DB::table('networks')->max('id') ?? 0,
        ) + 1;

        // channels.network_id has a real FK to networks.id (channels_network_id_foreign), so
        // moving the network's id straight to its new value fails on Postgres while the
        // channel row still references the old one. Break the reference first (the column is
        // nullable), move the network, then re-point the channel at its new id in one update.
        DB::table('channels')->where('id', $projectedChannel->id)->update(['network_id' => null]);
        DB::table('networks')->where('id', $network->id)->update(['id' => $collisionId]);
        DB::table('channels')->where('id', $projectedChannel->id)->update([
            'id' => $collisionId,
            'network_id' => $collisionId,
        ]);

        $network->id = $collisionId;
        $projectedChannel->id = $collisionId;
    }

    // The collision #1684 describes: these independently allocated primary
    // keys land on the exact same numeric value.
    expect($projectedChannel->id)->toBe($network->id);

    // The HDHR lineup/Xtream URL for this network playlist uses the projected
    // Channel's own id, not the Network's id - the fix must resolve through
    // channel->network_id rather than treating the route id as a Network id.
    $response = $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/{$projectedChannel->id}.m3u8");

    $response->assertRedirect($network->stream_url);
});

it('returns 404 when the projected channel does not resolve to a network', function () {
    $response = $this->get("/live/{$this->user->name}/{$this->playlist->uuid}/999999.m3u8");

    $response->assertNotFound();
});
