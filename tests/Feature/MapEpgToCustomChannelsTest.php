<?php

use App\Jobs\MapEpgToChannels;
use App\Jobs\MapPlaylistChannelsToEpgChunk;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->epg = Epg::factory()->for($this->user)->create();
    $this->batchNo = Str::orderedUuid()->toString();
    $this->map = EpgMap::factory()->create([
        'epg_id' => $this->epg->id,
        'user_id' => $this->user->id,
        'uuid' => $this->batchNo,
        'processing' => true,
    ]);
});

/** Run the matching and saving stages of "Map EPG to selected" for the given channels. */
function runEpgMapping(object $test, array $channelIds): void
{
    (new MapPlaylistChannelsToEpgChunk(
        channelIds: $channelIds,
        epgId: $test->epg->id,
        epgMapId: $test->map->id,
        settings: [],
        batchNo: $test->batchNo,
        totalChannels: count($channelIds),
    ))->handle();

    $jobIds = Job::where('batch_no', $test->batchNo)->pluck('id')->all();

    (new MapEpgToChannels($jobIds, max(1, count($jobIds)), $test->batchNo))->handle();
}

function epgChannelFor(object $test, string $channelId): EpgChannel
{
    return EpgChannel::factory()->create([
        'epg_id' => $test->epg->id,
        'user_id' => $test->user->id,
        'channel_id' => $channelId,
        'name' => $channelId,
        'display_name' => $channelId,
    ]);
}

function customChannel(object $test, CustomPlaylist $playlist, string $tvgId): Channel
{
    return Channel::factory()->create([
        'user_id' => $test->user->id,
        'playlist_id' => null,
        'source_id' => null,
        'custom_playlist_id' => $playlist->id,
        'is_custom' => true,
        'is_vod' => false,
        'epg_map_enabled' => true,
        'epg_channel_id' => null,
        'stream_id' => $tvgId,
        'stream_id_custom' => null,
        'title' => $tvgId,
        'name' => $tvgId,
    ]);
}

it('links custom channels (no source_id/playlist_id) by id', function () {
    $playlist = CustomPlaylist::factory()->for($this->user)->create();
    $first = customChannel($this, $playlist, 'WNBC');
    $second = customChannel($this, $playlist, 'WCBS');
    $third = customChannel($this, $playlist, 'WABC');

    $wnbc = epgChannelFor($this, 'WNBC');
    $wcbs = epgChannelFor($this, 'WCBS');
    $wabc = epgChannelFor($this, 'WABC');

    $channelsBefore = Channel::count();

    runEpgMapping($this, [$first->id, $second->id, $third->id]);

    expect($first->refresh()->epg_channel_id)->toBe($wnbc->id)
        ->and($second->refresh()->epg_channel_id)->toBe($wcbs->id)
        ->and($third->refresh()->epg_channel_id)->toBe($wabc->id)
        // No orphan channel rows are inserted.
        ->and(Channel::count())->toBe($channelsBefore);
});

it('still upserts imported playlist channels by source_id and playlist_id', function () {
    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    $channel = Channel::factory()->for($playlist)->for($this->user)->create([
        'source_id' => 'stream-1',
        'is_vod' => false,
        'epg_map_enabled' => true,
        'epg_channel_id' => null,
        'stream_id' => 'cnn.us',
        'stream_id_custom' => null,
    ]);
    $cnn = epgChannelFor($this, 'cnn.us');

    $channelsBefore = Channel::count();

    runEpgMapping($this, [$channel->id]);

    expect($channel->refresh()->epg_channel_id)->toBe($cnn->id)
        ->and(Channel::count())->toBe($channelsBefore);
});

it('links custom and imported channels mapped in the same run', function () {
    $custom = customChannel($this, CustomPlaylist::factory()->for($this->user)->create(), 'WNBC');
    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    $imported = Channel::factory()->for($playlist)->for($this->user)->create([
        'source_id' => 'stream-2',
        'is_vod' => false,
        'epg_map_enabled' => true,
        'epg_channel_id' => null,
        'stream_id' => 'cnn.us',
        'stream_id_custom' => null,
    ]);

    $wnbc = epgChannelFor($this, 'WNBC');
    $cnn = epgChannelFor($this, 'cnn.us');

    runEpgMapping($this, [$custom->id, $imported->id]);

    expect($custom->refresh()->epg_channel_id)->toBe($wnbc->id)
        ->and($imported->refresh()->epg_channel_id)->toBe($cnn->id);
});

it('maps only the live channel when a movie shares its source_id', function () {
    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create());
    $movie = Channel::factory()->for($playlist)->for($this->user)->create([
        'source_id' => '100',
        'is_vod' => true,
        'epg_channel_id' => null,
        'stream_id' => 'movie-a',
    ]);
    $live = Channel::factory()->for($playlist)->for($this->user)->create([
        'source_id' => '100',
        'is_vod' => false,
        'epg_map_enabled' => true,
        'epg_channel_id' => null,
        'stream_id' => 'cnn.us',
        'stream_id_custom' => null,
    ]);
    $cnn = epgChannelFor($this, 'cnn.us');

    $channelsBefore = Channel::count();

    runEpgMapping($this, [$live->id]);

    expect($live->refresh()->epg_channel_id)->toBe($cnn->id)
        ->and($movie->refresh()->epg_channel_id)->toBeNull()
        ->and(Channel::count())->toBe($channelsBefore);
});
