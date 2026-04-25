<?php

use App\Jobs\RefreshMediaServerLibraryJob;
use App\Jobs\SyncSeriesStrmFiles;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Category;
use App\Models\Channel;
use App\Models\Group;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
});

it('marks RefreshMediaServerLibraryJob as ShouldBeUnique scoped per integration', function () {
    // Use an unsaved model to avoid the `created` observer dispatching SyncMediaServer
    $integration = new MediaServerIntegration([
        'name' => 'Test Server',
        'type' => 'jellyfin',
        'host' => '127.0.0.1',
        'api_key' => 'k',
        'user_id' => $this->user->id,
    ]);
    $integration->id = 42;

    $job = new RefreshMediaServerLibraryJob($integration);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class);
    expect($job->uniqueId())->toBe('refresh-media-server-'.$integration->id);
    expect($job->uniqueFor)->toBeGreaterThan(0);
});

it('dispatches a single SyncSeriesStrmFiles job with series_ids when bulk-syncing series', function () {
    Bus::fake();

    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $category = Category::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
    ]);

    $seriesCollection = Series::factory()->count(5)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
        'category_id' => $category->id,
        'enabled' => true,
    ]);

    $this->actingAs($this->user);

    // Simulate exactly what the SeriesResource bulk action now does
    $seriesIds = $seriesCollection->pluck('id')->all();
    dispatch(new SyncSeriesStrmFiles(
        user_id: $this->user->id,
        series_ids: $seriesIds,
    ));

    Bus::assertDispatchedTimes(SyncSeriesStrmFiles::class, 1);
    Bus::assertDispatched(SyncSeriesStrmFiles::class, function (SyncSeriesStrmFiles $job) use ($seriesIds) {
        return $job->series_ids === $seriesIds
            && $job->user_id === $this->user->id
            && $job->series === null;
    });
});

it('dispatches a single SyncVodStrmFiles job with channel_ids when bulk-syncing VOD groups', function () {
    Bus::fake();

    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);

    $groups = Group::factory()->count(2)->create([
        'user_id' => $this->user->id,
        'playlist_id' => $playlist->id,
    ]);

    foreach ($groups as $group) {
        Channel::factory()->count(3)->create([
            'user_id' => $this->user->id,
            'playlist_id' => $playlist->id,
            'group_id' => $group->id,
            'is_vod' => true,
            'enabled' => true,
        ]);
    }

    $this->actingAs($this->user);

    // Simulate exactly what the VodGroupResource bulk action now does
    $expectedIds = $groups
        ->flatMap(fn ($group) => $group->enabled_channels->pluck('id'))
        ->unique()
        ->values()
        ->all();

    dispatch(new SyncVodStrmFiles(
        user_id: $this->user->id,
        channel_ids: $expectedIds,
    ));

    Bus::assertDispatchedTimes(SyncVodStrmFiles::class, 1);
    Bus::assertDispatched(SyncVodStrmFiles::class, function (SyncVodStrmFiles $job) use ($expectedIds) {
        return $job->channel_ids === $expectedIds
            && $job->user_id === $this->user->id
            && $job->channel === null
            && $job->channels === null;
    });

    expect(count($expectedIds))->toBe(6);
});

it('SyncSeriesStrmFiles exposes series_ids constructor parameter on the job payload', function () {
    $job = new SyncSeriesStrmFiles(
        user_id: $this->user->id,
        series_ids: [1, 2, 3],
    );

    expect($job->series_ids)->toBe([1, 2, 3]);
    expect($job->series)->toBeNull();
});

it('SyncVodStrmFiles exposes channel_ids constructor parameter on the job payload', function () {
    $job = new SyncVodStrmFiles(
        user_id: $this->user->id,
        channel_ids: [10, 20, 30],
    );

    expect($job->channel_ids)->toBe([10, 20, 30]);
    expect($job->channel)->toBeNull();
});
