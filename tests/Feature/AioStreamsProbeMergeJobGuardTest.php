<?php

use App\Jobs\MergeChannels;
use App\Jobs\ProbeChannelStreams;
use App\Jobs\ProbeChannelStreamsChunk;
use App\Jobs\ProbeChannelStreamsComplete;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Episode;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

/**
 * These guard the probe/merge jobs themselves (not just the Filament UI layer), so that
 * AIOStreams-added content can't be probed or merged even if it were passed in directly —
 * e.g. via an explicit channelIds/episodeIds array that bypasses the playlist-level
 * probe_enabled/can_merge filters, or if a bug elsewhere ever flips those flags back to true.
 */
beforeEach(function () {
    Notification::fake();

    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->playlist = Playlist::factory()->createQuietly(['user_id' => $this->user->id]);
    $this->group = Group::factory()->createQuietly(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);
});

it('excludes AIOStreams-added channels from ProbeChannelStreamsChunk even with explicit channelIds', function () {
    $aioChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'aio_integration_id' => $this->integration->id,
        // Simulate a bug/bypass upstream that left probing "enabled" on an AIO channel.
        'probe_enabled' => true,
    ]);
    $normalChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'probe_enabled' => true,
    ]);

    // notAioManaged(), not eligibleForProbe(): explicit channelIds are a manual "probe these"
    // action that intentionally bypasses probe_enabled — see the next test.
    $ids = Channel::whereIn('id', [$aioChannel->id, $normalChannel->id])->notAioManaged()->pluck('id');

    expect($ids)->toHaveCount(1)
        ->and($ids->first())->toBe($normalChannel->id);
});

it('still lets an explicit channelIds probe include a channel with probing disabled (manual override, not automatic)', function () {
    $disabledButSelected = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'probe_enabled' => false,
    ]);

    // The bulk "Probe Streams" action force-probes whatever the user explicitly selected,
    // regardless of the per-channel probe_enabled toggle — only AIOStreams content is exempt.
    $ids = Channel::whereIn('id', [$disabledButSelected->id])->notAioManaged()->pluck('id');

    expect($ids)->toHaveCount(1)->and($ids->first())->toBe($disabledButSelected->id);
});

it('excludes AIOStreams-added channels and episodes from ProbeStreamsChunk even with explicit ids', function () {
    $aioChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'is_vod' => true,
        'aio_integration_id' => $this->integration->id,
        'probe_enabled' => true,
    ]);
    $normalChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'is_vod' => true,
        'probe_enabled' => true,
    ]);

    $series = Series::factory()->create(['user_id' => $this->user->id]);
    $aioEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'series_id' => $series->id,
        'aio_item_id' => 'tt1:1:1',
        'probe_enabled' => true,
    ]);
    $normalEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'series_id' => $series->id,
        'probe_enabled' => true,
    ]);

    $channelIds = Channel::whereIn('id', [$aioChannel->id, $normalChannel->id])->notAioManaged()->pluck('id');
    $episodeIds = Episode::whereIn('id', [$aioEpisode->id, $normalEpisode->id])->notAioManaged()->pluck('id');

    expect($channelIds)->toHaveCount(1)->and($channelIds->first())->toBe($normalChannel->id)
        ->and($episodeIds)->toHaveCount(1)->and($episodeIds->first())->toBe($normalEpisode->id);
});

it('does not chain an AIOStreams-added channel into a probe batch when explicit channelIds are passed to ProbeChannelStreams', function () {
    Bus::fake();

    $aioChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'aio_integration_id' => $this->integration->id,
        'probe_enabled' => true,
    ]);
    $normalChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'probe_enabled' => true,
    ]);

    (new ProbeChannelStreams(channelIds: [$aioChannel->id, $normalChannel->id]))->handle();

    Bus::assertChained([
        fn (ProbeChannelStreamsChunk $job) => $job->channelIds === [$normalChannel->id],
        ProbeChannelStreamsComplete::class,
    ]);
});

it('probes an explicitly-selected channel via ProbeChannelStreams even when probe_enabled is false', function () {
    Bus::fake();

    $disabledButSelected = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'probe_enabled' => false,
    ]);

    (new ProbeChannelStreams(channelIds: [$disabledButSelected->id]))->handle();

    Bus::assertChained([
        fn (ProbeChannelStreamsChunk $job) => $job->channelIds === [$disabledButSelected->id],
        ProbeChannelStreamsComplete::class,
    ]);
});

it('does not let an AIOStreams-added channel be merged even if can_merge is somehow true', function () {
    $aioChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => '12345',
        'aio_integration_id' => $this->integration->id,
        // Simulate a bug/bypass that left merge "enabled" on an AIOStreams channel.
        'can_merge' => true,
        'enabled' => true,
    ]);
    $normalChannelA = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => '12345',
        'can_merge' => true,
        'enabled' => true,
    ]);
    $normalChannelB = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'group_id' => $this->group->id,
        'stream_id' => '12345',
        'can_merge' => true,
        'enabled' => true,
    ]);

    $playlists = collect([['playlist_failover_id' => $this->playlist->id]]);
    (new MergeChannels(
        user: $this->user,
        playlists: $playlists,
        playlistId: $this->playlist->id,
    ))->handle();

    // The two normal channels merged together, but the AIOStreams channel was never
    // considered as either a master or a failover candidate.
    expect(ChannelFailover::where('channel_failover_id', $aioChannel->id)->exists())->toBeFalse()
        ->and(ChannelFailover::where('channel_id', $aioChannel->id)->exists())->toBeFalse();

    $mergedIds = [$normalChannelA->id, $normalChannelB->id];
    $failover = ChannelFailover::whereIn('channel_id', $mergedIds)->orWhereIn('channel_failover_id', $mergedIds)->first();
    expect($failover)->not->toBeNull();
});
