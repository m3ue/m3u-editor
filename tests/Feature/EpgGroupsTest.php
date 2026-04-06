<?php

use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create([
        'dummy_epg' => false,
    ]);
    $this->actingAs($this->user);
});

it('returns distinct sorted groups for a playlist', function () {
    $sportsGroup = Group::factory()->create(['name' => 'Sports', 'user_id' => $this->user->id]);
    $newsGroup = Group::factory()->create(['name' => 'News', 'user_id' => $this->user->id]);

    Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $sportsGroup->id,
        'group' => 'Sports',
        'enabled' => true,
        'is_vod' => false,
    ]);

    Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $newsGroup->id,
        'group' => 'News',
        'enabled' => true,
        'is_vod' => false,
    ]);

    // Second channel in Sports — should not produce a duplicate
    Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $sportsGroup->id,
        'group' => 'Sports',
        'enabled' => true,
        'is_vod' => false,
    ]);

    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/groups");

    $response->assertOk()
        ->assertJsonStructure(['groups'])
        ->assertJson(['groups' => ['News', 'Sports']]); // alphabetically sorted
});

it('excludes disabled channels from groups response', function () {
    $group = Group::factory()->create(['name' => 'Hidden', 'user_id' => $this->user->id]);

    Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $group->id,
        'group' => 'Hidden',
        'enabled' => false,
        'is_vod' => false,
    ]);

    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/groups");

    $response->assertOk()
        ->assertJson(['groups' => []]);
});

it('falls back to group_internal when group is null', function () {
    Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group' => null,
        'group_internal' => 'Entertainment',
        'enabled' => true,
        'is_vod' => false,
    ]);

    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/groups");

    $response->assertOk()
        ->assertJson(['groups' => ['Entertainment']]);
});

it('returns 404 for unknown playlist uuid', function () {
    $this->getJson('/api/epg/playlist/nonexistent-uuid/groups')
        ->assertNotFound();
});

it('filters channels by group when group param is provided', function () {
    $sportsGroup = Group::factory()->create(['name' => 'Sports', 'user_id' => $this->user->id]);
    $newsGroup = Group::factory()->create(['name' => 'News', 'user_id' => $this->user->id]);

    $sportsChannel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $sportsGroup->id,
        'group' => 'Sports',
        'enabled' => true,
        'is_vod' => false,
        'channel' => 101,
    ]);

    $newsChannel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $newsGroup->id,
        'group' => 'News',
        'enabled' => true,
        'is_vod' => false,
        'channel' => 102,
    ]);

    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data?group=Sports");

    $response->assertOk();

    $data = $response->json();
    $channels = $data['channels'];

    expect($channels)->toHaveKey((string) $sportsChannel->channel)
        ->and($channels)->not->toHaveKey((string) $newsChannel->channel);
});

it('returns all channels when no group param is provided', function () {
    $sportsGroup = Group::factory()->create(['name' => 'Sports', 'user_id' => $this->user->id]);
    $newsGroup = Group::factory()->create(['name' => 'News', 'user_id' => $this->user->id]);

    Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $sportsGroup->id,
        'group' => 'Sports',
        'enabled' => true,
        'is_vod' => false,
        'channel' => 201,
    ]);

    Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $newsGroup->id,
        'group' => 'News',
        'enabled' => true,
        'is_vod' => false,
        'channel' => 202,
    ]);

    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data");

    $response->assertOk();

    $data = $response->json();
    expect($data['channels'])->toHaveCount(2);
});

it('group filter is case-insensitive', function () {
    $group = Group::factory()->create(['name' => 'Sports', 'user_id' => $this->user->id]);

    $channel = Channel::factory()->create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'group_id' => $group->id,
        'group' => 'Sports',
        'enabled' => true,
        'is_vod' => false,
        'channel' => 301,
    ]);

    $response = $this->getJson("/api/epg/playlist/{$this->playlist->uuid}/data?group=SPORTS");

    $response->assertOk();
    $data = $response->json();

    expect($data['channels'])->toHaveKey((string) $channel->channel);
});
