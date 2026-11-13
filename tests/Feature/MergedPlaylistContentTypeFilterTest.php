<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\Season;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->merged = MergedPlaylist::factory()->for($this->user)->create();
});

it('includes all content types from a source by default', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($playlist->id);

    Channel::factory()->create(['playlist_id' => $playlist->id, 'is_vod' => false]);
    Channel::factory()->create(['playlist_id' => $playlist->id, 'is_vod' => true]);
    Series::factory()->create(['playlist_id' => $playlist->id, 'user_id' => $this->user->id]);

    expect($this->merged->channels()->count())->toBe(2)
        ->and($this->merged->live_channels()->count())->toBe(1)
        ->and($this->merged->vod_channels()->count())->toBe(1)
        ->and($this->merged->series()->count())->toBe(1);
});

it('excludes live channels from a source with include_live disabled', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($playlist->id, ['include_live' => false]);

    Channel::factory()->create(['playlist_id' => $playlist->id, 'is_vod' => false]);
    $vod = Channel::factory()->create(['playlist_id' => $playlist->id, 'is_vod' => true]);

    expect($this->merged->channels()->pluck('id')->all())->toBe([$vod->id])
        ->and($this->merged->live_channels()->count())->toBe(0)
        ->and($this->merged->vod_channels()->count())->toBe(1);
});

it('excludes vod channels from a source with include_vod disabled', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($playlist->id, ['include_vod' => false]);

    $live = Channel::factory()->create(['playlist_id' => $playlist->id, 'is_vod' => false]);
    Channel::factory()->create(['playlist_id' => $playlist->id, 'is_vod' => true]);

    expect($this->merged->channels()->pluck('id')->all())->toBe([$live->id]);
});

it('excludes series from a source with include_series disabled', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($playlist->id, ['include_series' => false]);

    Series::factory()->create(['playlist_id' => $playlist->id, 'user_id' => $this->user->id]);

    expect($this->merged->series()->count())->toBe(0);
});

it('applies per-source toggles independently across multiple attached sources', function () {
    $liveOnly = Playlist::factory()->create(['user_id' => $this->user->id]);
    $vodOnly = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($liveOnly->id, ['include_vod' => false, 'include_series' => false]);
    $this->merged->playlists()->attach($vodOnly->id, ['include_live' => false]);

    $liveChannel = Channel::factory()->create(['playlist_id' => $liveOnly->id, 'is_vod' => false]);
    Channel::factory()->create(['playlist_id' => $liveOnly->id, 'is_vod' => true]);
    Channel::factory()->create(['playlist_id' => $vodOnly->id, 'is_vod' => false]);
    $vodChannel = Channel::factory()->create(['playlist_id' => $vodOnly->id, 'is_vod' => true]);

    $ids = $this->merged->channels()->pluck('id')->sort()->values()->all();
    expect($ids)->toBe(collect([$liveChannel->id, $vodChannel->id])->sort()->values()->all());
});

it('still includes DVR-created series, seasons and episodes from a source with include_series disabled', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($playlist->id, ['include_series' => false]);

    $owned = ['playlist_id' => $playlist->id, 'user_id' => $this->user->id];
    $dvr = [...$owned, 'import_batch_no' => 'dvr'];

    Series::factory()->create($owned);
    $dvrSeries = Series::factory()->create($dvr);
    $dvrSeason = Season::factory()->for($dvrSeries)->create($dvr);
    $dvrEpisode = Episode::factory()->for($dvrSeries)->for($dvrSeason)->create($dvr);
    Episode::factory()->for($dvrSeries)->for($dvrSeason)->create($owned);

    expect($this->merged->series()->pluck('series.id')->all())->toBe([$dvrSeries->id])
        ->and($this->merged->seasons()->pluck('seasons.id')->all())->toBe([$dvrSeason->id])
        ->and($this->merged->episodes()->pluck('episodes.id')->all())->toBe([$dvrEpisode->id]);
});

it('excludes DVR-created series from a source that is fully excluded from the merge', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($playlist->id, ['include_live' => false, 'include_vod' => false, 'include_series' => false]);

    Series::factory()->create(['playlist_id' => $playlist->id, 'user_id' => $this->user->id, 'import_batch_no' => 'dvr']);

    expect($this->merged->series()->count())->toBe(0);
});

it('applies the DVR series rule to aliases of a merged playlist', function () {
    $playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->merged->playlists()->attach($playlist->id, ['include_series' => false]);

    Series::factory()->create(['playlist_id' => $playlist->id, 'user_id' => $this->user->id]);
    $dvrSeries = Series::factory()->create(['playlist_id' => $playlist->id, 'user_id' => $this->user->id, 'import_batch_no' => 'dvr']);

    $alias = PlaylistAlias::create([
        'name' => 'Merged Alias',
        'uuid' => fake()->uuid(),
        'user_id' => $this->user->id,
        'merged_playlist_id' => $this->merged->id,
    ]);

    expect($alias->series()->pluck('series.id')->all())->toBe([$dvrSeries->id]);
});
