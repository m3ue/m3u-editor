<?php

use App\Jobs\NotifyAioStreamsResolutionComplete;
use App\Jobs\ResolveAioStreamsChannel;
use App\Jobs\ResolveAioStreamsEpisode;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
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
        'manifest_url' => 'https://aiostreams.test/manifest.json',
        'playlist_id' => $this->playlist->id,
    ]);
    $this->series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
    ]);
});

it('resolves failed channels and episodes, and scheduled episodes whose air date has passed', function () {
    Bus::fake();

    $failedChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
        'aio_resolution_status' => 'failed',
    ]);

    $dueEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $this->series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:1',
        'aio_resolution_status' => 'scheduled',
        'aio_air_date' => now()->subMinute(),
    ]);

    $failedEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $this->series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:2',
        'aio_resolution_status' => 'failed',
    ]);

    $notYetDueEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $this->series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:3',
        'aio_resolution_status' => 'scheduled',
        'aio_air_date' => now()->addDay(),
    ]);

    $this->artisan('app:resolve-pending-aiostreams-candidates')->assertSuccessful();

    Bus::assertDispatched(ResolveAioStreamsChannel::class, fn ($job) => $job->channelId === $failedChannel->id);
    Bus::assertDispatched(ResolveAioStreamsEpisode::class, fn ($job) => $job->episodeId === $dueEpisode->id);
    Bus::assertDispatched(ResolveAioStreamsEpisode::class, fn ($job) => $job->episodeId === $failedEpisode->id);
    Bus::assertNotDispatched(ResolveAioStreamsEpisode::class, fn ($job) => $job->episodeId === $notYetDueEpisode->id);

    // All four items belong to the same user, so a single grouped notification job
    // is queued covering the failed channel plus both resolved-eligible episodes.
    Bus::assertDispatched(NotifyAioStreamsResolutionComplete::class, function ($job) use ($failedChannel, $dueEpisode, $failedEpisode) {
        return $job->userId === $this->user->id
            && $job->channelIds === [$failedChannel->id]
            && empty(array_diff([$dueEpisode->id, $failedEpisode->id], $job->episodeIds))
            && count($job->episodeIds) === 2;
    });
});

it('never re-dispatches resolution for already-resolved candidates (debrid ban-avoidance)', function () {
    Bus::fake();

    $resolvedChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
        'aio_resolution_status' => 'resolved',
        'aio_last_resolved_at' => now()->subDays(30),
    ]);

    $resolvedEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $this->series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:1',
        'aio_resolution_status' => 'resolved',
        'aio_last_resolved_at' => now()->subDays(30),
    ]);

    $this->artisan('app:resolve-pending-aiostreams-candidates')->assertSuccessful();

    Bus::assertNotDispatched(ResolveAioStreamsChannel::class, fn ($job) => $job->channelId === $resolvedChannel->id);
    Bus::assertNotDispatched(ResolveAioStreamsEpisode::class, fn ($job) => $job->episodeId === $resolvedEpisode->id);
    Bus::assertNotDispatched(NotifyAioStreamsResolutionComplete::class);
});
