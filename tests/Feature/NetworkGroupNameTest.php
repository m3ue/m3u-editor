<?php

use App\Models\Group;
use App\Models\Network;
use App\Models\Playlist;
use App\Services\NetworkChannelSyncService;

it('uses custom group_name when syncing network as channel', function () {
    $playlist = Playlist::factory()->create(['include_networks_in_m3u' => true]);
    $network = Network::factory()->create([
        'user_id' => $playlist->user_id,
        'group_name' => 'My Custom Group',
    ]);

    $service = app(NetworkChannelSyncService::class);
    $channel = $service->syncNetworkAsChannel($playlist, $network);

    expect($channel->group_internal)->toBe('My Custom Group');

    $group = Group::find($channel->group_id);
    expect($group->name)->toBe('My Custom Group');
    expect($group->name_internal)->toBe('My Custom Group');
});

it('falls back to "Networks" when group_name is null', function () {
    $playlist = Playlist::factory()->create(['include_networks_in_m3u' => true]);
    $network = Network::factory()->create([
        'user_id' => $playlist->user_id,
        'group_name' => null,
    ]);

    $service = app(NetworkChannelSyncService::class);
    $channel = $service->syncNetworkAsChannel($playlist, $network);

    expect($channel->group_internal)->toBe('Networks');

    $group = Group::find($channel->group_id);
    expect($group->name)->toBe('Networks');
});

it('falls back to "Networks" when group_name is empty string', function () {
    $playlist = Playlist::factory()->create(['include_networks_in_m3u' => true]);
    $network = Network::factory()->create([
        'user_id' => $playlist->user_id,
        'group_name' => '',
    ]);

    $service = app(NetworkChannelSyncService::class);
    $channel = $service->syncNetworkAsChannel($playlist, $network);

    expect($channel->group_internal)->toBe('Networks');
});

it('updates channel group when network group_name changes via refreshNetworkChannel', function () {
    $playlist = Playlist::factory()->create(['include_networks_in_m3u' => true]);
    $network = Network::factory()->create([
        'user_id' => $playlist->user_id,
        'group_name' => 'Original Group',
    ]);

    $service = app(NetworkChannelSyncService::class);

    // Initial sync
    $channel = $service->syncNetworkAsChannel($playlist, $network);
    expect($channel->group_internal)->toBe('Original Group');

    // Change the group name
    $network->group_name = 'Updated Group';
    $network->saveQuietly();

    // Refresh should update the group
    $service->refreshNetworkChannel($network);

    $channel->refresh();
    expect($channel->group_internal)->toBe('Updated Group');

    $group = Group::find($channel->group_id);
    expect($group->name)->toBe('Updated Group');
});

it('returns correct effective_group_name from accessor', function () {
    $networkWithName = Network::factory()->make(['group_name' => 'Custom']);
    $networkWithNull = Network::factory()->make(['group_name' => null]);
    $networkWithEmpty = Network::factory()->make(['group_name' => '']);

    expect($networkWithName->effective_group_name)->toBe('Custom');
    expect($networkWithNull->effective_group_name)->toBe('Networks');
    expect($networkWithEmpty->effective_group_name)->toBe('Networks');
});

it('creates separate groups for networks with different group names', function () {
    $playlist = Playlist::factory()->create(['include_networks_in_m3u' => true]);
    $network1 = Network::factory()->create([
        'user_id' => $playlist->user_id,
        'group_name' => 'Sports',
    ]);
    $network2 = Network::factory()->create([
        'user_id' => $playlist->user_id,
        'group_name' => 'Movies',
    ]);

    $service = app(NetworkChannelSyncService::class);
    $channel1 = $service->syncNetworkAsChannel($playlist, $network1);
    $channel2 = $service->syncNetworkAsChannel($playlist, $network2);

    expect($channel1->group_id)->not->toBe($channel2->group_id);
    expect($channel1->group_internal)->toBe('Sports');
    expect($channel2->group_internal)->toBe('Movies');
});
