<?php

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\Series;
use App\Models\User;
use App\Models\ViewerWatchProgress;
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

    expect($progress->fresh()->stream_id)->toBe($newEpisode->id);
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
