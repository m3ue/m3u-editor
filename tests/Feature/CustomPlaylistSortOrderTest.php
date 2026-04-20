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

use App\Enums\PlaylistChannelId;
use App\Http\Controllers\PlaylistGenerateController;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Tags\Tag;

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->group = Group::factory()->for($this->playlist)->for($this->user)->create(['sort_order' => 1]);
    $this->customPlaylist = CustomPlaylist::factory()->for($this->user)->create();

    // Clear any stale EPG cache files so tests don't serve cached results from previous runs
    Storage::disk('local')->deleteDirectory('playlist-epg-files');
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

// ---------------------------------------------------------------------------
// EPG XML: channel id uses pivot channel_number (must match HDHR lineup GuideNumber)
// ---------------------------------------------------------------------------

it('EPG XML uses pivot channel_number for channel id when id_channel_by is Number', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'channel' => 99,
        'title' => 'Das Erste HDraw',
        'url' => 'http://example.com/stream/1',
    ]);

    $this->customPlaylist->update(['id_channel_by' => PlaylistChannelId::Number, 'dummy_epg' => true]);
    $this->customPlaylist->channels()->attach($channel->id, ['channel_number' => 42]);

    // Use compressed endpoint — returns a regular (non-streamed) response, avoiding ob_start conflicts
    $response = $this->get("/{$this->customPlaylist->uuid}/epg.xml.gz");
    $response->assertStatus(200);

    $content = gzdecode($response->getContent());

    // EPG channel id must be pivot channel_number (42), not global channel (99)
    expect($content)->toContain('<channel id="42">')
        ->not->toContain('<channel id="99">');
});

it('EPG XML falls back to global channel number when pivot channel_number is null for Number mode', function () {
    $channel = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'channel' => 77,
        'title' => 'ZDF HDraw',
        'url' => 'http://example.com/stream/1',
    ]);

    $this->customPlaylist->update(['id_channel_by' => PlaylistChannelId::Number, 'dummy_epg' => true]);
    $this->customPlaylist->channels()->attach($channel->id);

    $response = $this->get("/{$this->customPlaylist->uuid}/epg.xml.gz");
    $response->assertStatus(200);

    $content = gzdecode($response->getContent());

    expect($content)->toContain('<channel id="77">');
});

// ---------------------------------------------------------------------------
// getChannelQuery: COALESCE(pivot.sort, channels.sort) ordering
// ---------------------------------------------------------------------------

it('getChannelQuery orders channels by pivot sort when all channels have pivot sort set', function () {
    // channelFirst has a high channels.sort (100) but pivot.sort=1 — should come first
    $channelFirst = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 100,
        'title' => 'Channel First',
    ]);
    // channelSecond has a low channels.sort (2) but pivot.sort=50 — should come second
    $channelSecond = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 2,
        'title' => 'Channel Second',
    ]);

    $this->customPlaylist->channels()->attach($channelFirst->id, ['sort' => 1]);
    $this->customPlaylist->channels()->attach($channelSecond->id, ['sort' => 50]);

    $channels = PlaylistGenerateController::getChannelQuery($this->customPlaylist)->get();

    expect($channels->first()->id)->toBe($channelFirst->id)
        ->and($channels->last()->id)->toBe($channelSecond->id);
});

it('getChannelQuery falls back to channels.sort when pivot sort is null', function () {
    $channelFirst = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 1,
        'title' => 'Channel First',
    ]);
    $channelSecond = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 2,
        'title' => 'Channel Second',
    ]);

    // Attach without pivot sort (null)
    $this->customPlaylist->channels()->attach([$channelFirst->id, $channelSecond->id]);

    $channels = PlaylistGenerateController::getChannelQuery($this->customPlaylist)->get();

    expect($channels->first()->id)->toBe($channelFirst->id)
        ->and($channels->last()->id)->toBe($channelSecond->id);
});

it('getChannelQuery pivot sort takes precedence over channels.sort in mixed scenario', function () {
    // channelA has pivot.sort=1 but a very high channels.sort — COALESCE picks pivot
    $channelA = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 100,
        'title' => 'Channel A',
    ]);
    // channelB has no pivot sort — COALESCE falls back to channels.sort=2
    $channelB = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
        'enabled' => true,
        'is_vod' => false,
        'sort' => 2,
        'title' => 'Channel B',
    ]);

    $this->customPlaylist->channels()->attach($channelA->id, ['sort' => 1]);
    $this->customPlaylist->channels()->attach($channelB->id);

    $channels = PlaylistGenerateController::getChannelQuery($this->customPlaylist)->get();

    // COALESCE(1, 100)=1 for A, COALESCE(null, 2)=2 for B → A comes first
    // Without COALESCE (channels.sort only): B(2) before A(100) — opposite result
    expect($channels->first()->id)->toBe($channelA->id)
        ->and($channels->last()->id)->toBe($channelB->id);
});

it('EPG channel ids match HDHR lineup GuideNumbers for custom playlists', function () {
    $channels = collect();
    for ($i = 1; $i <= 3; $i++) {
        $ch = Channel::factory()->for($this->user)->for($this->playlist)->for($this->group)->create([
            'enabled' => true,
            'is_vod' => false,
            'channel' => 100 + $i,
            'title' => "Channel {$i}",
            'url' => "http://example.com/stream/{$i}",
        ]);
        $channels->push($ch);
        $this->customPlaylist->channels()->attach($ch->id, ['channel_number' => $i * 10]);
    }

    $this->customPlaylist->update(['id_channel_by' => PlaylistChannelId::Number, 'dummy_epg' => true]);

    // Get EPG XML (compressed — avoids streamed response ob_start conflicts)
    $epgResponse = $this->get("/{$this->customPlaylist->uuid}/epg.xml.gz");
    $epgContent = gzdecode($epgResponse->getContent());

    // Get HDHR lineup
    $hdhrResponse = $this->get("/{$this->customPlaylist->uuid}/hdhr/lineup.json");
    $hdhrContent = json_decode($hdhrResponse->streamedContent(), true);

    // Both should use pivot channel_number values (10, 20, 30)
    foreach ($hdhrContent as $idx => $entry) {
        $guideNumber = $entry['GuideNumber'];
        expect($epgContent)->toContain("<channel id=\"{$guideNumber}\">");
    }

    // Must NOT contain global channel numbers
    expect($epgContent)
        ->not->toContain('<channel id="101">')
        ->not->toContain('<channel id="102">')
        ->not->toContain('<channel id="103">');
});
