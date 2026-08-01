<?php

use App\Jobs\ResolveAioStreamsEpisode;
use App\Models\Episode;
use App\Models\EpisodeFailover;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\M3uProxyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/abc/manifest.json',
        'playlist_id' => $this->playlist->id,
    ]);
    $this->series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
    ]);
    $this->episode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $this->series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:1',
        'aio_resolution_status' => 'resolved',
        'url' => 'https://example.com/primary.mkv',
    ]);
});

it('returns the next failover episode url when one is available', function () {
    $failoverEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $this->series->id,
        'is_custom' => true,
        'is_aio_failover_clone' => true,
        'url' => 'https://example.com/failover.mkv',
    ]);

    EpisodeFailover::create([
        'user_id' => $this->user->id,
        'episode_id' => $this->episode->id,
        'episode_failover_id' => $failoverEpisode->id,
        'sort' => 1,
    ]);

    $result = app(M3uProxyService::class)->resolveEpisodeFailoverUrl(
        $this->episode->id,
        $this->playlist->uuid,
        'https://example.com/primary.mkv',
        index: 0,
    );

    expect($result['next_url'])->toBe('https://example.com/failover.mkv');
});

it('dispatches ResolveAioStreamsEpisode to refresh candidates once the failover chain is exhausted', function () {
    Bus::fake();

    $result = app(M3uProxyService::class)->resolveEpisodeFailoverUrl(
        $this->episode->id,
        $this->playlist->uuid,
        'https://example.com/primary.mkv',
        index: 0,
    );

    expect($result['next_url'])->toBeNull();

    Bus::assertDispatched(ResolveAioStreamsEpisode::class, function (ResolveAioStreamsEpisode $job) {
        return $job->episodeId === $this->episode->id;
    });
});

it('does not dispatch a re-resolve for non-AIOStreams episodes when failovers are exhausted', function () {
    Bus::fake();

    $plainEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $this->series->id,
        'is_custom' => false,
        'aio_item_id' => null,
        'url' => 'https://example.com/plain.mkv',
    ]);

    app(M3uProxyService::class)->resolveEpisodeFailoverUrl(
        $plainEpisode->id,
        $this->playlist->uuid,
        'https://example.com/plain.mkv',
        index: 0,
    );

    Bus::assertNotDispatched(ResolveAioStreamsEpisode::class);
});

it('routes episode metadata to the episode failover resolver via the API endpoint', function () {
    Bus::fake();

    $response = $this->postJson('/api/m3u-proxy/failover-resolver', [
        'current_url' => 'https://example.com/primary.mkv',
        'current_failover_index' => 0,
        'metadata' => [
            'id' => $this->episode->id,
            'type' => 'episode',
            'playlist_uuid' => $this->playlist->uuid,
        ],
    ]);

    $response->assertOk()->assertJson(['next_url' => null]);

    Bus::assertDispatched(ResolveAioStreamsEpisode::class, function (ResolveAioStreamsEpisode $job) {
        return $job->episodeId === $this->episode->id;
    });
});
