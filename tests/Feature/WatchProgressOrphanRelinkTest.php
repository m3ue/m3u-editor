<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\Series;
use App\Models\User;
use App\Models\ViewerWatchProgress;
use App\Services\WatchProgressLinker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->playlistAuth = PlaylistAuth::create([
        'name' => 'Test',
        'username' => 'testuser',
        'password' => 'testpass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($this->playlistAuth);

    $this->viewer = PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => 'admin',
        'is_admin' => false,
        'playlist_auth_id' => $this->playlistAuth->id,
        'viewerable_type' => $this->playlist->getMorphClass(),
        'viewerable_id' => $this->playlist->id,
    ]);
});

function getRecentlyWatchedForViewer(PlaylistViewer $viewer): TestResponse
{
    $query = http_build_query([
        'username' => 'testuser',
        'password' => 'testpass',
        'action' => 'get_recently_watched',
        'viewer_id' => $viewer->ulid,
    ]);

    return test()->getJson(route('xtream.api.player').'?'.$query);
}

it('drops a vod progress row whose stream_id no longer resolves and has no tmdb_id to relink from', function () {
    ViewerWatchProgress::create([
        'playlist_viewer_id' => $this->viewer->id,
        'content_type' => 'vod',
        'stream_id' => 999999,
        'tmdb_id' => null,
        'position_seconds' => 120,
        'duration_seconds' => 1800,
        'completed' => false,
        'last_watched_at' => now(),
    ]);

    $response = getRecentlyWatchedForViewer($this->viewer);

    $response->assertOk();
    expect($response->json())->toBe([]);
});

it('relinks a vod progress row to its new stream_id via tmdb_id after a library flush', function () {
    $newChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'tmdb_id' => 550,
        'name' => 'Fight Club',
        'title' => 'Fight Club',
    ]);

    $progress = ViewerWatchProgress::create([
        'playlist_viewer_id' => $this->viewer->id,
        'content_type' => 'vod',
        'stream_id' => 999999, // stale id from before the flush
        'tmdb_id' => 550,
        'position_seconds' => 120,
        'duration_seconds' => 1800,
        'completed' => false,
        'last_watched_at' => now(),
    ]);

    $response = getRecentlyWatchedForViewer($this->viewer);

    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveCount(1);
    expect($data[0]['stream_id'])->toBe($newChannel->id);
    expect($data[0]['title'])->toBe('Fight Club');

    expect($progress->fresh()->stream_id)->toBe($newChannel->id);
});

it('relinks an episode progress row to its new stream_id via tmdb_id after a library flush', function () {
    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);
    $newEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'tmdb_id' => 12345,
        'season' => 1,
        'episode_num' => 2,
        'title' => 'The Pilot',
    ]);

    $progress = ViewerWatchProgress::create([
        'playlist_viewer_id' => $this->viewer->id,
        'content_type' => 'episode',
        'stream_id' => 888888, // stale id from before the flush
        'tmdb_id' => 12345,
        'series_id' => 424242, // stale too - not used for relinking
        'season_number' => 1,
        'episode_number' => 2,
        'position_seconds' => 120,
        'duration_seconds' => 1800,
        'completed' => false,
        'last_watched_at' => now(),
    ]);

    $response = getRecentlyWatchedForViewer($this->viewer);

    $response->assertOk();
    $data = $response->json();
    expect($data)->toHaveCount(1);
    expect($data[0]['stream_id'])->toBe($newEpisode->id);
    expect($data[0]['series_id'])->toBe($series->id);

    expect($progress->fresh()->stream_id)->toBe($newEpisode->id);
    expect($progress->fresh()->series_id)->toBe($series->id);
});

it('repairs a stale series_id on an episode row that already resolves', function () {
    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);
    $episode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
    ]);

    // Relinked before series_id followed the episode: the stream_id is
    // current but series_id still points at the deleted pre-flush series.
    $progress = ViewerWatchProgress::create([
        'playlist_viewer_id' => $this->viewer->id,
        'content_type' => 'episode',
        'stream_id' => $episode->id,
        'series_id' => 424242,
        'position_seconds' => 120,
        'duration_seconds' => 1800,
        'completed' => false,
        'last_watched_at' => now(),
    ]);

    $response = getRecentlyWatchedForViewer($this->viewer);

    $response->assertOk();
    expect($response->json('0.series_id'))->toBe($series->id);
    expect($progress->fresh()->series_id)->toBe($series->id);
});

it('stores tmdb_id when recording vod watch progress', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'tmdb_id' => 550,
    ]);

    $prefix = base64_encode($this->playlist->uuid).'_';
    $response = test()
        ->withSession([
            "{$prefix}guest_auth_username" => 'testuser',
            "{$prefix}guest_auth_password" => 'testpass',
        ])
        ->postJson('/api/watch-progress', [
            'content_type' => 'vod',
            'stream_id' => $channel->id,
            'position_seconds' => 100,
            'duration_seconds' => 1800,
            'playlist_id' => $this->playlist->id,
        ]);

    $response->assertOk();

    expect(ViewerWatchProgress::where('stream_id', $channel->id)->first()->tmdb_id)->toBe(550);
});

function orphanedEpisodeProgress(PlaylistViewer $viewer, Series $series, int $season, int $episode, array $attributes = []): ViewerWatchProgress
{
    return ViewerWatchProgress::create(array_merge([
        'playlist_viewer_id' => $viewer->id,
        'content_type' => 'episode',
        'stream_id' => 999999,
        'series_id' => $series->id,
        'season_number' => $season,
        'episode_number' => $episode,
        'tmdb_id' => null,
        'position_seconds' => 120,
        'duration_seconds' => 1800,
        'completed' => false,
        'last_watched_at' => now(),
    ], $attributes));
}

it('relinks an episode without a tmdb_id by series, season and episode number (issue #1819)', function () {
    $series = Series::factory()->for($this->user)->for($this->playlist)->create();
    $episode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'season' => 2,
        'episode_num' => 5,
    ]);
    $progress = orphanedEpisodeProgress($this->viewer, $series, 2, 5);

    $response = getRecentlyWatchedForViewer($this->viewer);

    $response->assertOk();
    expect($response->json())->toHaveCount(1)
        ->and($progress->refresh()->stream_id)->toBe($episode->id);
});

it('does not relink across series or to a different episode number', function () {
    $series = Series::factory()->for($this->user)->for($this->playlist)->create();
    $otherSeries = Series::factory()->for($this->user)->for($this->playlist)->create();
    Episode::factory()->create([
        'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id,
        'series_id' => $otherSeries->id, 'season' => 2, 'episode_num' => 5,
    ]);
    Episode::factory()->create([
        'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id,
        'series_id' => $series->id, 'season' => 2, 'episode_num' => 6,
    ]);
    $progress = orphanedEpisodeProgress($this->viewer, $series, 2, 5);

    getRecentlyWatchedForViewer($this->viewer)->assertOk();

    expect($progress->refresh()->stream_id)->toBe(999999);
});

it('keeps a relinkable episode row out of the prune', function () {
    $series = Series::factory()->for($this->user)->for($this->playlist)->create();
    $episode = Episode::factory()->create([
        'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id,
        'series_id' => $series->id, 'season' => 2, 'episode_num' => 5,
    ]);
    $progress = orphanedEpisodeProgress($this->viewer, $series, 2, 5);

    app(WatchProgressLinker::class)->pruneOrphaned();

    expect($progress->fresh()?->stream_id)->toBe($episode->id);
});

it('keeps relinking older rows after an unrecoverable one (issue #1819)', function () {
    $series = Series::factory()->for($this->user)->for($this->playlist)->create();
    $episode = Episode::factory()->create([
        'user_id' => $this->user->id, 'playlist_id' => $this->playlist->id,
        'series_id' => $series->id, 'season' => 1, 'episode_num' => 1,
    ]);

    // Newest row first: nothing left to relink it to.
    orphanedEpisodeProgress($this->viewer, $series, 9, 9, ['last_watched_at' => now()]);
    $older = orphanedEpisodeProgress($this->viewer, $series, 1, 1, ['stream_id' => 888888, 'last_watched_at' => now()->subDay()]);

    getRecentlyWatchedForViewer($this->viewer)->assertOk();

    expect($older->refresh()->stream_id)->toBe($episode->id);
});
