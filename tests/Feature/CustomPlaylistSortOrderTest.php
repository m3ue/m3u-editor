<?php

/**
 * Tests for Custom Playlist custom sort ordering and pivot channel_number support.
 *
 * PR: https://github.com/m3ue/m3u-editor/pull/784
 *
 * Verifies that:
 * - Custom tag group ordering is respected in the channel query (getChannelQuery)
 * - The pivot channel_number is used for tvg-chno in M3U output
 * - The pivot channel_number is used in HDHR lineup output
 * - The pivot channel_number is used in Xtream API get_live_streams
 * - The pivot channel_number is used in Xtream API get_vod_streams
 */

use App\Http\Controllers\PlaylistGenerateController;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Tags\Tag;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->group = Group::factory()->for($this->playlist)->for($this->user)->create(['sort_order' => 1]);
    $this->customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
});

// ---------------------------------------------------------------------------
// getChannelQuery ordering
// ---------------------------------------------------------------------------

it('getChannelQuery orders custom playlist channels by custom tag order_column', function () {
    // Spatie Tags auto-increments order_column; update explicitly after creation
    $tagB = Tag::create(['name' => ['en' => 'Group B'], 'type' => $this->customPlaylist->uuid]);
    $tagA = Tag::create(['name' => ['en' => 'Group A'], 'type' => $this->customPlaylist->uuid]);
    $tagB->update(['order_column' => 2]);
    $tagA->update(['order_column' => 1]);

    $channelB = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 1,
        'title' => 'Channel B',
    ]);
    $channelA = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 1,
        'title' => 'Channel A',
    ]);

    $this->customPlaylist->channels()->attach([$channelB->id, $channelA->id]);
    $channelB->attachTag($tagB);
    $channelA->attachTag($tagA);

    $channels = PlaylistGenerateController::getChannelQuery($this->customPlaylist)->get();

    // Channel A (tag order 1) should come before Channel B (tag order 2)
    expect($channels->first()->id)->toBe($channelA->id)
        ->and($channels->last()->id)->toBe($channelB->id);
});

it('getChannelQuery falls back to group sort_order for channels without custom tags', function () {
    $groupFirst = Group::factory()->for($this->playlist)->for($this->user)->create(['sort_order' => 1]);
    $groupSecond = Group::factory()->for($this->playlist)->for($this->user)->create(['sort_order' => 2]);

    $channelSecond = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 1,
        'group_id' => $groupSecond->id,
        'title' => 'Channel Second',
    ]);
    $channelFirst = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 1,
        'group_id' => $groupFirst->id,
        'title' => 'Channel First',
    ]);

    $this->customPlaylist->channels()->attach([$channelSecond->id, $channelFirst->id]);

    $channels = PlaylistGenerateController::getChannelQuery($this->customPlaylist)->get();

    // Channel First (group sort_order 1) should come before Channel Second (group sort_order 2)
    expect($channels->first()->id)->toBe($channelFirst->id)
        ->and($channels->last()->id)->toBe($channelSecond->id);
});

// ---------------------------------------------------------------------------
// M3U output: tvg-chno uses pivot channel_number
// ---------------------------------------------------------------------------

it('M3U output uses pivot channel_number for tvg-chno in custom playlists', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'channel' => 99,
        'title' => 'Test Channel',
        'url' => 'http://example.com/stream/1',
    ]);

    // Attach with a custom channel_number (42) that differs from global (99)
    $this->customPlaylist->channels()->attach($channel->id, ['channel_number' => 42]);

    $response = $this->get("/{$this->customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    expect($response->streamedContent())->toContain('tvg-chno="42"');
});

it('M3U output falls back to global channel number when pivot channel_number is null', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'channel' => 77,
        'title' => 'Test Channel',
        'url' => 'http://example.com/stream/1',
    ]);

    // Attach without a channel_number
    $this->customPlaylist->channels()->attach($channel->id);

    $response = $this->get("/{$this->customPlaylist->uuid}/playlist.m3u");
    $response->assertStatus(200);

    expect($response->streamedContent())->toContain('tvg-chno="77"');
});

// ---------------------------------------------------------------------------
// HDHR lineup: no undefined variable error and respects pivot channel_number
// ---------------------------------------------------------------------------

it('HDHR lineup returns valid JSON for custom playlists without undefined variable errors', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'title' => 'Test Channel',
        'url' => 'http://example.com/stream/1',
    ]);

    $this->customPlaylist->channels()->attach($channel->id, ['channel_number' => 5]);

    $response = $this->get("/{$this->customPlaylist->uuid}/hdhr/lineup.json");
    $response->assertStatus(200);

    $decoded = json_decode($response->streamedContent(), true);
    expect($decoded)->toBeArray()->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// Xtream API: get_live_streams uses pivot channel_number
// ---------------------------------------------------------------------------

it('Xtream API get_live_streams uses pivot channel_number as num for custom playlists', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'channel' => 99,
        'title' => 'Live Channel',
        'url' => 'http://example.com/stream/1',
    ]);

    $this->customPlaylist->channels()->attach($channel->id, ['channel_number' => 55]);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->customPlaylist->uuid).'&action=get_live_streams');

    $response->assertStatus(200);
    $streams = $response->json();

    expect($streams)->toBeArray()->toHaveCount(1)
        ->and($streams[0]['num'])->toBe(55);
});

it('Xtream API get_live_streams falls back to global channel number when pivot is null', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'channel' => 33,
        'title' => 'Live Channel',
        'url' => 'http://example.com/stream/1',
    ]);

    $this->customPlaylist->channels()->attach($channel->id);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->customPlaylist->uuid).'&action=get_live_streams');

    $response->assertStatus(200);
    $streams = $response->json();

    expect($streams)->toBeArray()->toHaveCount(1)
        ->and($streams[0]['num'])->toBe(33);
});

// ---------------------------------------------------------------------------
// Xtream API: get_vod_streams uses pivot channel_number
// ---------------------------------------------------------------------------

it('Xtream API get_vod_streams uses pivot channel_number as num for custom playlists', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => true,
        'channel' => 99,
        'title' => 'VOD Movie',
        'url' => 'http://example.com/movie/1',
        'container_extension' => 'mkv',
    ]);

    $this->customPlaylist->channels()->attach($channel->id, ['channel_number' => 7]);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->customPlaylist->uuid).'&action=get_vod_streams');

    $response->assertStatus(200);
    $streams = $response->json();

    expect($streams)->toBeArray()->toHaveCount(1)
        ->and($streams[0]['num'])->toBe(7);
});

it('Xtream API get_vod_streams falls back to global channel number when pivot is null', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => true,
        'channel' => 22,
        'title' => 'VOD Movie',
        'url' => 'http://example.com/movie/1',
        'container_extension' => 'mkv',
    ]);

    $this->customPlaylist->channels()->attach($channel->id);

    $response = $this->getJson('/player_api.php?username='.urlencode($this->user->name).'&password='.urlencode($this->customPlaylist->uuid).'&action=get_vod_streams');

    $response->assertStatus(200);
    $streams = $response->json();

    expect($streams)->toBeArray()->toHaveCount(1)
        ->and($streams[0]['num'])->toBe(22);
});
