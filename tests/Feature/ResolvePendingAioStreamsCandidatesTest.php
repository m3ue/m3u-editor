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
use App\Services\AioStreamsCandidateService;
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
        'aio_integration_id' => $this->integration->id,
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

it('rotates titles that keep failing instead of retrying the same batch forever', function () {
    Bus::fake();

    $stuck = collect(range(1, 3))->map(fn (int $i) => Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
        'aio_resolution_status' => 'failed',
        'updated_at' => now()->subDays(10 - $i),
    ]));
    $waiting = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
        'aio_resolution_status' => 'failed',
        'updated_at' => now()->subDay(),
    ]);

    // Another attempt that still finds nothing re-marks the (already failed) rows.
    $this->travel(1)->minute();
    $service = app(AioStreamsCandidateService::class);
    $stuck->each(fn (Channel $channel) => $service->markFailed($channel->fresh()));

    $this->artisan('app:resolve-pending-aiostreams-candidates', ['--limit' => 2]);

    Bus::assertDispatched(ResolveAioStreamsChannel::class, fn ($job) => $job->channelId === $waiting->id);
});

it('splits the batch so failed movies cannot starve episodes', function () {
    Bus::fake();

    foreach (range(1, 4) as $i) {
        Channel::factory()->create([
            'user_id' => $this->user->id,
            'playlist_id' => $this->playlist->id,
            'is_custom' => true,
            'aio_integration_id' => $this->integration->id,
            'aio_resolution_status' => 'failed',
        ]);
    }
    $dueEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $this->series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt9:1:1',
        'aio_resolution_status' => 'scheduled',
        'aio_air_date' => now()->subMinute(),
    ]);

    $this->artisan('app:resolve-pending-aiostreams-candidates', ['--limit' => 4]);

    // Two movies up front, the one due episode, then movies take back the unused slot.
    Bus::assertDispatchedTimes(ResolveAioStreamsChannel::class, 3);
    Bus::assertDispatched(ResolveAioStreamsEpisode::class, fn ($job) => $job->episodeId === $dueEpisode->id);
});

it('skips episodes whose series lost its integration', function () {
    Bus::fake();

    $orphanedSeries = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => null,
    ]);
    $orphaned = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $orphanedSeries->id,
        'is_custom' => true,
        'aio_item_id' => 'tt8:1:1',
        'aio_resolution_status' => 'failed',
        'updated_at' => now()->subYear(),
    ]);
    $live = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $this->series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt8:1:2',
        'aio_resolution_status' => 'failed',
    ]);

    $this->artisan('app:resolve-pending-aiostreams-candidates', ['--limit' => 1]);

    Bus::assertDispatched(ResolveAioStreamsEpisode::class, fn ($job) => $job->episodeId === $live->id);
    Bus::assertNotDispatched(ResolveAioStreamsEpisode::class, fn ($job) => $job->episodeId === $orphaned->id);
});
