<?php

/**
 * A bulk "Sync .strm files" over a very large selection passes every selected id to the
 * job. Bound as query parameters, those ids exceeded the driver's placeholder limit
 * (65535 on Postgres, 32766 on SQLite) and the job failed before writing any file.
 */

use App\Jobs\CheckSeriesStrmProgress;
use App\Jobs\CheckVodStrmProgress;
use App\Jobs\SyncSeriesStrmFiles;
use App\Jobs\SyncVodStrmFiles;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

it('syncs VOD with more selected ids than the database parameter limit', function () {
    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'enabled' => true,
        'is_vod' => true,
    ]);

    $channelIds = [$channel->id, ...range(10_000_000, 10_070_000)];

    (new SyncVodStrmFiles(user_id: $this->user->id, channel_ids: $channelIds))
        ->handle(app(GeneralSettings::class));

    Bus::assertChained([
        SyncVodStrmFiles::class,
        fn (CheckVodStrmProgress $job) => $job->totalChannels === 1,
    ]);
});

it('syncs series with more selected ids than the database parameter limit', function () {
    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'enabled' => true,
    ]);

    $seriesIds = [$series->id, ...range(10_000_000, 10_070_000)];

    (new SyncSeriesStrmFiles(user_id: $this->user->id, series_ids: $seriesIds))
        ->handle(app(GeneralSettings::class));

    Bus::assertChained([
        SyncSeriesStrmFiles::class,
        fn (CheckSeriesStrmProgress $job) => $job->totalSeries === 1,
    ]);
});
