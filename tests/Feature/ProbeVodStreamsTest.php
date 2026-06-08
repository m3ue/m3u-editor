<?php

use App\Enums\SyncRunPhase;
use App\Events\PlaylistCreated;
use App\Events\PlaylistUpdated;
use App\Jobs\ProbeVodStreams;
use App\Jobs\ProbeVodStreamsChunk;
use App\Jobs\ProbeVodStreamsComplete;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\User;
use App\Services\SyncPipelineService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Event::fake([PlaylistCreated::class, PlaylistUpdated::class]);
    Bus::fake();
    Notification::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
});

it('can include disabled vod channels while still honoring probe opt out', function () {
    $enabled = Channel::factory()->for($this->playlist)->create([
        'enabled' => true,
        'is_vod' => true,
        'probe_enabled' => true,
        'stream_stats_probed_at' => null,
    ]);
    $disabled = Channel::factory()->for($this->playlist)->create([
        'enabled' => false,
        'is_vod' => true,
        'probe_enabled' => true,
        'stream_stats_probed_at' => null,
    ]);
    Channel::factory()->for($this->playlist)->create([
        'enabled' => false,
        'is_vod' => true,
        'probe_enabled' => false,
        'stream_stats_probed_at' => null,
    ]);

    (new ProbeVodStreams(
        playlistId: $this->playlist->id,
        includeDisabled: true,
    ))->handle();

    Bus::assertChained([
        fn (ProbeVodStreamsChunk $job) => empty(array_diff([$enabled->id, $disabled->id], $job->channelIds))
            && $job->episodeIds === []
            && count($job->channelIds) === 2,
        ProbeVodStreamsComplete::class,
    ]);
});

it('can probe already probed vod channels when only unprobed is false', function () {
    $probed = Channel::factory()->for($this->playlist)->create([
        'enabled' => true,
        'is_vod' => true,
        'probe_enabled' => true,
        'stream_stats_probed_at' => now()->subDay(),
    ]);
    $unprobed = Channel::factory()->for($this->playlist)->create([
        'enabled' => true,
        'is_vod' => true,
        'probe_enabled' => true,
        'stream_stats_probed_at' => null,
    ]);

    (new ProbeVodStreams(
        playlistId: $this->playlist->id,
        onlyUnprobed: false,
    ))->handle();

    Bus::assertChained([
        fn (ProbeVodStreamsChunk $job) => empty(array_diff([$probed->id, $unprobed->id], $job->channelIds))
            && $job->episodeIds === []
            && count($job->channelIds) === 2,
        ProbeVodStreamsComplete::class,
    ]);
});

it('can include disabled series episodes while still honoring probe opt out', function () {
    $enabled = Episode::factory()->for($this->playlist)->create([
        'enabled' => true,
        'probe_enabled' => true,
        'stream_stats_probed_at' => null,
    ]);
    $disabled = Episode::factory()->for($this->playlist)->create([
        'enabled' => false,
        'probe_enabled' => true,
        'stream_stats_probed_at' => null,
    ]);
    Episode::factory()->for($this->playlist)->create([
        'enabled' => false,
        'probe_enabled' => false,
        'stream_stats_probed_at' => null,
    ]);

    (new ProbeVodStreams(
        playlistId: $this->playlist->id,
        includeDisabled: true,
        isSeriesProbe: true,
    ))->handle();

    Bus::assertChained([
        fn (ProbeVodStreamsChunk $job) => $job->channelIds === []
            && empty(array_diff([$enabled->id, $disabled->id], $job->episodeIds))
            && count($job->episodeIds) === 2,
        ProbeVodStreamsComplete::class,
    ]);
});

it('can probe already probed series episodes when only unprobed is false', function () {
    $probed = Episode::factory()->for($this->playlist)->create([
        'enabled' => true,
        'probe_enabled' => true,
        'stream_stats_probed_at' => now()->subDay(),
    ]);
    $unprobed = Episode::factory()->for($this->playlist)->create([
        'enabled' => true,
        'probe_enabled' => true,
        'stream_stats_probed_at' => null,
    ]);

    (new ProbeVodStreams(
        playlistId: $this->playlist->id,
        onlyUnprobed: false,
        isSeriesProbe: true,
    ))->handle();

    Bus::assertChained([
        fn (ProbeVodStreamsChunk $job) => $job->channelIds === []
            && empty(array_diff([$probed->id, $unprobed->id], $job->episodeIds))
            && count($job->episodeIds) === 2,
        ProbeVodStreamsComplete::class,
    ]);
});

it('completes vod probe phase when no streams are eligible', function () {
    Channel::factory()->for($this->playlist)->create([
        'enabled' => true,
        'is_vod' => true,
        'probe_enabled' => true,
        'stream_stats_probed_at' => now()->subDay(),
    ]);

    $service = Mockery::mock(SyncPipelineService::class);
    $service->shouldReceive('completePhase')
        ->once()
        ->with(123, SyncRunPhase::VodProbe);
    app()->instance(SyncPipelineService::class, $service);

    (new ProbeVodStreams(
        playlistId: $this->playlist->id,
        syncRunId: 123,
    ))->handle();
});

it('completes series probe phase when no streams are eligible', function () {
    Episode::factory()->for($this->playlist)->create([
        'enabled' => true,
        'probe_enabled' => true,
        'stream_stats_probed_at' => now()->subDay(),
    ]);

    $service = Mockery::mock(SyncPipelineService::class);
    $service->shouldReceive('completePhase')
        ->once()
        ->with(456, SyncRunPhase::SeriesProbe);
    app()->instance(SyncPipelineService::class, $service);

    (new ProbeVodStreams(
        playlistId: $this->playlist->id,
        syncRunId: 456,
        isSeriesProbe: true,
    ))->handle();
});
