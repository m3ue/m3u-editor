<?php

use App\Jobs\MergeChannels;
use App\Jobs\ProbeStreamsChunk;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\PlaylistService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();
    Notification::fake();
});

function runVodMerge(User $user, Collection $playlists, int $playlistId, ?array $weightedConfig): void
{
    (new MergeChannels(
        user: $user,
        playlists: $playlists,
        playlistId: $playlistId,
        deactivateFailoverChannels: true,
        weightedConfig: $weightedConfig,
        contentType: 'vod',
        mergeKey: 'tmdb_id',
    ))->handle();
}

it('promotes the higher-resolution VOD duplicate to master using filename resolution', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly();
    $playlist2 = Playlist::factory()->for($user)->createQuietly();

    $sevenTwentyP = Channel::factory()->create([
        'is_vod' => true,
        'tmdb_id' => 12345,
        'stream_id' => 'provider-a-1',
        'name' => 'Movie.720p',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'group_id' => null,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $fourK = Channel::factory()->create([
        'is_vod' => true,
        'tmdb_id' => 12345,
        'stream_id' => 'provider-b-2',
        'name' => 'Movie.4K.UHD',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'group_id' => null,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $weightedConfig = PlaylistService::buildMergeWeightedConfig([
        'vod_resolution_priority_enabled' => true,
        'vod_use_filename_resolution' => true,
    ], 'vod');

    runVodMerge($user, collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
    ]), $playlist1->id, $weightedConfig);

    // The 4K channel (in the non-preferred playlist) outranks the 720p channel.
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $fourK->id,
        'channel_failover_id' => $sevenTwentyP->id,
        'sort' => 1,
    ]);
    $this->assertDatabaseMissing('channel_failovers', [
        'channel_id' => $sevenTwentyP->id,
        'channel_failover_id' => $fourK->id,
    ]);

    expect($fourK->refresh()->enabled)->toBeTrue()
        ->and($sevenTwentyP->refresh()->enabled)->toBeFalse();
});

it('falls back to playlist priority when filename resolution is disabled', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly();
    $playlist2 = Playlist::factory()->for($user)->createQuietly();

    $sevenTwentyP = Channel::factory()->create([
        'is_vod' => true,
        'tmdb_id' => 12345,
        'stream_id' => 'provider-a-1',
        'name' => 'Movie.720p',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'group_id' => null,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $fourK = Channel::factory()->create([
        'is_vod' => true,
        'tmdb_id' => 12345,
        'stream_id' => 'provider-b-2',
        'name' => 'Movie.4K.UHD',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'group_id' => null,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $weightedConfig = PlaylistService::buildMergeWeightedConfig([
        'vod_resolution_priority_enabled' => true,
        'vod_use_filename_resolution' => false,
    ], 'vod');

    runVodMerge($user, collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
    ]), $playlist1->id, $weightedConfig);

    // Without filename resolution both channels score zero on resolution, so the
    // preferred playlist (playlist1) wins on playlist priority.
    $this->assertDatabaseHas('channel_failovers', [
        'channel_id' => $sevenTwentyP->id,
        'channel_failover_id' => $fourK->id,
        'sort' => 1,
    ]);
    $this->assertDatabaseMissing('channel_failovers', [
        'channel_id' => $fourK->id,
        'channel_failover_id' => $sevenTwentyP->id,
    ]);
});

it('queues an ffprobe for filename-derived resolution when verification is enabled', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly(['auto_probe_vod_streams' => true]);
    $playlist2 = Playlist::factory()->for($user)->createQuietly();

    $sevenTwentyP = Channel::factory()->create([
        'is_vod' => true,
        'tmdb_id' => 12345,
        'stream_id' => 'provider-a-1',
        'name' => 'Movie.720p',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'group_id' => null,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $fourK = Channel::factory()->create([
        'is_vod' => true,
        'tmdb_id' => 12345,
        'stream_id' => 'provider-b-2',
        'name' => 'Movie.4K.UHD',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'group_id' => null,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $weightedConfig = PlaylistService::buildMergeWeightedConfig([
        'vod_resolution_priority_enabled' => true,
        'vod_use_filename_resolution' => true,
        'vod_verify_filename_via_probe' => true,
    ], 'vod');

    runVodMerge($user, collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
    ]), $playlist1->id, $weightedConfig);

    Bus::assertDispatched(ProbeStreamsChunk::class, function ($job) use ($sevenTwentyP, $fourK) {
        return in_array($sevenTwentyP->id, $job->channelIds, true)
            && in_array($fourK->id, $job->channelIds, true);
    });
});

it('does not queue an ffprobe for filename-derived resolution by default', function () {
    $user = User::factory()->create();
    $playlist1 = Playlist::factory()->for($user)->createQuietly(['auto_probe_vod_streams' => true]);
    $playlist2 = Playlist::factory()->for($user)->createQuietly();

    $sevenTwentyP = Channel::factory()->create([
        'is_vod' => true,
        'tmdb_id' => 12345,
        'stream_id' => 'provider-a-1',
        'name' => 'Movie.720p',
        'user_id' => $user->id,
        'playlist_id' => $playlist1->id,
        'group_id' => null,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $fourK = Channel::factory()->create([
        'is_vod' => true,
        'tmdb_id' => 12345,
        'stream_id' => 'provider-b-2',
        'name' => 'Movie.4K.UHD',
        'user_id' => $user->id,
        'playlist_id' => $playlist2->id,
        'group_id' => null,
        'enabled' => true,
        'can_merge' => true,
    ]);

    $weightedConfig = PlaylistService::buildMergeWeightedConfig([
        'vod_resolution_priority_enabled' => true,
        'vod_use_filename_resolution' => true,
    ], 'vod');

    runVodMerge($user, collect([
        ['playlist_failover_id' => $playlist1->id],
        ['playlist_failover_id' => $playlist2->id],
    ]), $playlist1->id, $weightedConfig);

    Bus::assertNotDispatched(ProbeStreamsChunk::class);
});
