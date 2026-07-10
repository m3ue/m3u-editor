<?php

use App\Models\Network;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns both networks when two share the same channel number', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['is_network_playlist' => true]);

    // Two networks with the same channel_number — previously the second would
    // overwrite the first in the $channels/$programmes arrays.
    Network::factory()->for($user)->create([
        'name' => 'Network A',
        'channel_number' => 5,
        'enabled' => true,
        'network_playlist_id' => $playlist->id,
    ]);

    Network::factory()->for($user)->create([
        'name' => 'Network B',
        'channel_number' => 5,
        'enabled' => true,
        'network_playlist_id' => $playlist->id,
    ]);

    $this->actingAs($user);

    $response = $this->getJson("/api/epg/playlist/{$playlist->uuid}/data");

    $response->assertOk();

    $channels = $response->json('channels');
    expect($channels)->toHaveCount(2);

    $names = collect($channels)->pluck('display_name')->sort()->values()->all();
    expect($names)->toBe(['Network A', 'Network B']);
});

it('returns both networks when neither has a channel number', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['is_network_playlist' => true]);

    Network::factory()->for($user)->create([
        'name' => 'Network X',
        'channel_number' => null,
        'enabled' => true,
        'network_playlist_id' => $playlist->id,
    ]);

    Network::factory()->for($user)->create([
        'name' => 'Network Y',
        'channel_number' => null,
        'enabled' => true,
        'network_playlist_id' => $playlist->id,
    ]);

    $this->actingAs($user);

    $response = $this->getJson("/api/epg/playlist/{$playlist->uuid}/data");

    $response->assertOk();

    $channels = $response->json('channels');
    expect($channels)->toHaveCount(2);
});
