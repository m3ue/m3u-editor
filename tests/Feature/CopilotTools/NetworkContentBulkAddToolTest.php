<?php

use App\Filament\CopilotTools\NetworkContentBulkAddTool;
use App\Models\Channel;
use App\Models\Network;
use App\Models\NetworkContent;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

function makeBulkAddTool(): NetworkContentBulkAddTool
{
    return new NetworkContentBulkAddTool;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function makeBulkChannel(User $user, Playlist $playlist, array $overrides = []): Channel
{
    return Channel::factory()->create(array_merge([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'is_vod' => true,
    ], $overrides));
}

beforeEach(function () {
    Event::fake();
    Queue::fake();
});

it('adds channels to a network and returns a summary', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $network = Network::factory()->for($user)->create(['name' => 'My Network']);
    $channelA = makeBulkChannel($user, $playlist, ['name' => 'Aladdin']);
    $channelB = makeBulkChannel($user, $playlist, ['name' => 'Bambi']);

    $this->actingAs($user);

    $result = (string) makeBulkAddTool()->handle(new Request([
        'network_id' => $network->id,
        'channel_ids' => [$channelA->id, $channelB->id],
    ]));

    expect($result)->toContain('My Network')
        ->toContain('Aladdin')
        ->toContain('Bambi')
        ->toContain('Added: 2');

    expect(NetworkContent::where('network_id', $network->id)->count())->toBe(2);
});

it('skips channels already in the network', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $network = Network::factory()->for($user)->create();
    $channel = makeBulkChannel($user, $playlist, ['name' => 'Lion King']);

    // Pre-add the channel
    NetworkContent::withoutEvents(function () use ($network, $channel) {
        $network->networkContent()->create([
            'contentable_type' => Channel::class,
            'contentable_id' => $channel->id,
            'sort_order' => 1,
            'weight' => 1,
        ]);
    });

    $this->actingAs($user);

    $result = (string) makeBulkAddTool()->handle(new Request([
        'network_id' => $network->id,
        'channel_ids' => [$channel->id],
    ]));

    expect($result)->toContain('Skipped')
        ->toContain('Lion King');

    // Should still be exactly 1 row (not duplicated)
    expect(NetworkContent::where('network_id', $network->id)->count())->toBe(1);
});

it('returns error when network does not belong to the user', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $network = Network::factory()->for($userB)->create();
    $playlist = Playlist::factory()->for($userA)->create();
    $channel = makeBulkChannel($userA, $playlist);

    $this->actingAs($userA);

    $result = (string) makeBulkAddTool()->handle(new Request([
        'network_id' => $network->id,
        'channel_ids' => [$channel->id],
    ]));

    expect($result)->toContain('not found');
    expect(NetworkContent::count())->toBe(0);
});

it('reports channel IDs not in the user library', function () {
    $user = User::factory()->create();
    $network = Network::factory()->for($user)->create();

    $this->actingAs($user);

    $result = (string) makeBulkAddTool()->handle(new Request([
        'network_id' => $network->id,
        'channel_ids' => [99999],
    ]));

    expect($result)->toContain('Not found')
        ->toContain('99999');
});

it('sets sort_order sequentially after existing content', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $network = Network::factory()->for($user)->create();
    $channelA = makeBulkChannel($user, $playlist);
    $channelB = makeBulkChannel($user, $playlist);
    $channelC = makeBulkChannel($user, $playlist);

    // Pre-seed two items with sort_order 1 and 2
    NetworkContent::withoutEvents(function () use ($network, $channelA, $channelB) {
        $network->networkContent()->create([
            'contentable_type' => Channel::class,
            'contentable_id' => $channelA->id,
            'sort_order' => 1,
            'weight' => 1,
        ]);
        $network->networkContent()->create([
            'contentable_type' => Channel::class,
            'contentable_id' => $channelB->id,
            'sort_order' => 2,
            'weight' => 1,
        ]);
    });

    $this->actingAs($user);

    makeBulkAddTool()->handle(new Request([
        'network_id' => $network->id,
        'channel_ids' => [$channelC->id],
    ]));

    $newEntry = NetworkContent::where('contentable_id', $channelC->id)->first();
    expect($newEntry)->not->toBeNull();
    expect($newEntry->sort_order)->toBe(3);
});

it('returns error when no channel IDs are provided', function () {
    $user = User::factory()->create();
    $network = Network::factory()->for($user)->create();

    $this->actingAs($user);

    $result = (string) makeBulkAddTool()->handle(new Request([
        'network_id' => $network->id,
        'channel_ids' => [],
    ]));

    expect($result)->toContain('No channel IDs');
});

it('does not add channels from another user library', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $network = Network::factory()->for($userA)->create();
    $playlistB = Playlist::factory()->for($userB)->create();
    $otherChannel = makeBulkChannel($userB, $playlistB);

    $this->actingAs($userA);

    $result = (string) makeBulkAddTool()->handle(new Request([
        'network_id' => $network->id,
        'channel_ids' => [$otherChannel->id],
    ]));

    expect($result)->toContain('Not found');
    expect(NetworkContent::count())->toBe(0);
});
